<?php
// translator ready
// addnews ready
// mail ready

function charrestore_getmoduleinfo(){
	$info = array(
			"name"=>"Character Restorer",
			"category"=>"Administrative",
			"version"=>"1.2",
			"author"=>"Eric Stevens, modifications +nb",
			"download"=>"core_module",
			"settings"=>array(
				"General,title",
				"auto_snapshot"=>"Create character snapshots upon character expiration?,bool|1",
				"email_hash_salt"=>"Salt Value for your server. NEVER CHANGE THIS AFTER THE FIRST CHANGE!,text|CHANGEME",
				"Thresholds,title",
				"dk_threshold"=>"&nbsp;&nbsp;+-- Dragon Kill threshold above which snapshots will be taken?,int|5",
				"lvl_threshold"=>"&nbsp;&nbsp;&nbsp;&nbsp;+-- Level within this DK above which snapshots will be taken?,int|0",
				"manual_snapshot"=>"Create a snapshot when a char is manually deleted?,bool|0",
				"suicide_snapshot"=>"Create a snapshot when a user deletes themselves?,bool|0",
				"permadeath_snapshot"=>"Create a snapshot when a user perma-dies?,bool|1",
				"Perma death is not current implemented at the time of writing this module; nor do I have any plans that way; it just made sense to reserve it in case either I or someone else ever introduced this option.,note",
				"Directory,title",
				"snapshot_dir"=>"Location to store snapshots|../logd_snapshots",
				"Notifications and expirations,title",
				//				"notifymail"=>"Notify the restored char owner via mail?,bool|1",
				"Users get a mail upon expiration with a token - put here your sender data in,note",
				"adminname"=>"Name of the Sender of the email,text|Noname",
				"adminmail"=>"Emailaddress of the Sender,text|noreply@noreply.com",

				),
			"prefs"=>array(
					"hasaccess"=>"Has Access to the restorer,bool|0",
				      ),
			);
	return $info;
}

function charrestore_install(){
	module_addhook("delete_character");
	module_addhook("superuser");
	module_addhook("petition-status");
	module_addhook_priority("addpetition",50);
	module_addhook_priority("petitionform",50);
	return true;
}

function charrestore_uninstall(){
	return true;
}

function charrestore_dohook($hookname,$args){
	switch ($hookname) {
		case "petitionform":
			//add some fields to the petition for charrestore
			require_once("lib/showform.php");
			$charrestore = httpget('charrestore');
			if ($charrestore==1) {
				$fields = array(
						"Character Restore Form,title",
						"login"=>"Login Name",
						"last_online_time"=>"Last Online (approx.)",
						"registered_email_address"=>"Registered email address",
						"oro_kills"=>"Amount of Oro Kills",
						"custom_name"=>"Custom Name (if any)",
					       );
				$vals = array();
				showform($fields,$vals,true);
			} else {
				output("`n`\$If you are trying to restore a character, click here: ");
				rawoutput("<a href='petition.php?charrestore=1'>".translate_inline("Character Restore Form","petition")."</a>");
				output("`n`0");
			}
			break;
		case "superuser":
			global $session;
			$hasaccess = (int)get_module_pref("hasaccess");
			if (($session['user']['superuser'] & SU_EDIT_USERS) || $hasaccess ){
				addnav("Character Restore");
				addnav("Restore a deleted char",
						"runmodule.php?module=charrestore&op=list&admin=true");
			}
			break;
		case "petition-status":
			global $session;
			$hasaccess = (int)get_module_pref("hasaccess");
			$retid = (int)httpget('id');
			if ((($session['user']['superuser'] & SU_EDIT_USERS) && $retid>0) || $hasaccess ){
				addnav("Character Restore");
				addnav("Restore a deleted char",
						"runmodule.php?module=charrestore&op=list&admin=true&returnpetition=$retid");
			}
			break;
		case "delete_character":
			if ($args['deltype']==CHAR_DELETE_AUTO &&
					!get_module_setting("auto_snapshot")) return $args;
			if ($args['deltype']==CHAR_DELETE_MANUAL &&
					!get_module_setting("manual_snapshot")) return $args;
			if ($args['deltype']==CHAR_DELETE_SUICIDE &&
					!get_module_setting("suicide_snapshot")) return $args;
			if ($args['deltype']==CHAR_DELETE_PERMADEATH &&
					!get_module_setting("permadeath_snapshot")) return $args;
			//time to create a snapshot.
			$sql = "SELECT * FROM ".db_prefix("accounts")." WHERE acctid='{$args['acctid']}'";
			$result = db_query($sql);
			if (db_num_rows($result) > 0){
				$row = db_fetch_assoc($result);

				//test if the user is below the snapshot threshold
				if ($args['deltype']==CHAR_DELETE_AUTO){
					if ($row['dragonkills']<get_module_setting("dk_threshold") ||
							$row['dragonkills']==get_module_setting("dk_threshold") &&
							$row['level'] < get_module_setting("lvl_threshold")){
						return $args;
					}
				}

				$user = array("account"=>array(),"prefs"=>array());

				//set up the user's account table fields
				//reduces storage footprint.
				//id and ip are not necessary and also related to identify persons (stripped)
				$nosavefields = array("output"=>true,"allowednavs"=>true,"lastip"=>true,"uniqueid"=>true);
				foreach ($row as $key=>$val) {
					if (!isset($nosavefields[$key])){
						$user['account'][$key] = $val;
					}
				}
				//time to remove personal data so we can store a copy indefinitely
				$user_email = $user['account']['emailaddress'];
				$user['account']['emailaddress']=charrestore_gethash($user['account']['emailaddress']);
				$user['account']['replaceemail']=charrestore_gethash($user['account']['replaceemail']);

				//set up the user's module preferences
				//add a hook for module to not include themselves (data privacy issue)
				$nosavemodules = modulehook('charrestore_nosavemodules',array()); // "output"=>true,"allowednavs"=>true,"ipaddress","id");
				$sql = "SELECT * FROM ".db_prefix("module_userprefs")." WHERE userid='{$args['acctid']}'";
				$prefs = db_query($sql);
				while ($row = db_fetch_assoc($prefs)){
					if (!isset($user['prefs'][$row['modulename']])){
						$user['prefs'][$row['modulename']] = array();
					}
					if (!isset($nosavemodules[$row['modulename']])) {
						$user['prefs'][$row['modulename']][$row['setting']] = $row['value'];
					}
				}

				//write the file
				$path = charrestore_getstorepath();
				$fp = @fopen($path.str_replace(" ","_",$user['account']['login'])."|".$user['account']['acctid']."|".date("Ymd"),"w+");
				$failure = true;
				if ($fp){
					if (fwrite($fp,
								serialize($user)
						  )!==false){
						$failure=false;
					}else{
						$failure=true;
					}
					fclose($fp);
				} 
				if ($failure === true ) {
					$errstr=("Path not openable or error writing: ".$path.str_replace(" ","_",$user['account']['login'])."|".$user['account']['acctid']."|".date("Ymd"));
					$errno=E_USER_ERROR;
					$errfile="charrestore.php";
					$errline=169;	
					logd_error_handler($errno, $errstr, $errfile, $errline);
					//quit now, which is should anyways, this is a precaution, so we don't delete the data of the char
					exit(0);
				}
				$targetid=$user['account']['acctid'];
				$targetmail=$user_email;
				$subject=translate_mail(array("Your character %s",sanitize($user['account']['login'])),$targetid);
				$body=translate_mail(array(
							"Your character %s has been deleted by you or has expired on the game. `nIf you choose to reactivate this account in the future, note that it will be archived but without personal data. `n`nThis means, your email address and other personal data will be removed from the copy. If you want it restored, you need to recall your email adress or your password, only this will work!`n`nRegards,\nStaff of %s",
							sanitize($user['account']['login']),get_module_setting('adminname','charrestore')),
						$targetid);
				$body = str_replace("`n","</br>",$body);
				$result=charrestore_sendmail($targetmail,$body,$subject,get_module_setting('adminmail','charrestore'),get_module_setting('adminname','charrestore'));
				if ($result) {
					output("`\$The notification message has been sent!`n");
				} else {
					output("`\$There has been an error! The notification message was NOT sent!`n");
				}

			}
	}
	return $args;
}

function charrestore_getstorepath(){
	//returns a valid path name where snapshots are stored.
	$path = get_module_setting("snapshot_dir","charrestore");
	if (substr($path,-1)!="/" && substr($path,-1)!="\\"){
		$path = $path."/";
	}
	return $path;
}

function charrestore_run(){
	global $session;
	//	check_su_access(SU_EDIT_USERS);
	require_once("lib/superusernav.php");
	$retid = (int)httpget('returnpetition');
	//allow backlink to petition
	page_header("Character Restore");
	superusernav();
	if ($retid>0) {
		addnav("Petition");
		addnav("Return to petition","viewpetition.php?op=view&id=$retid");
		$retnav = "&returnpetition=$retid";
	} else $retnav = "";
	addnav("Functions");
	addnav("Search","runmodule.php?module=charrestore&op=list".$retnav);
	addnav("Convert Email to Hash","runmodule.php?module=charrestore&op=hashtest".$retnav);

	addnav("Legacy Converts");
	addnav("Convert Email to Hash","runmodule.php?module=charrestore&op=hashconvert".$retnav);

	if (httpget("op")=="list"){
		output("Please note that only characters who have reached at least level %s in DK %s will have been saved!`n`n", get_module_setting("lvl_threshold","charrestore"), get_module_setting("dk_threshold","charrestore"));

		output("Search by login, email or both:`n");
		rawoutput("<form action='runmodule.php?module=charrestore&op=list$retnav' method='POST'>");
		addnav("","runmodule.php?module=charrestore&op=list".$retnav);
		rawoutput("<table><tr><td>");
		output("Character Login: ");
		rawoutput("<input name='login' value=\"".htmlentities(stripslashes(httppost("login")), ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\"><br>");
		rawoutput("</td><td>");
		output("Character Email: ");
		rawoutput("<input name='email' value=\"".htmlentities(stripslashes(httppost("email")), ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\"><br>");
		rawoutput("</td></tr></table>");
		output("After date: ");
		rawoutput("<input name='start' value=\"".htmlentities(stripslashes(httppost("start")), ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\"><br>");
		output("Before date: ");
		rawoutput("<input name='end' value=\"".htmlentities(stripslashes(httppost("end")), ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\"><br>");
		$submit = translate_inline("Submit");
		rawoutput("<input type='submit' value='$submit' class='button'>");
		rawoutput("</form>");
		//do the search.
		$login = httppost("login");
		$email = httppost("email");
		$start = httppost("start");
		$end = httppost("end");
		if ($start > "") $start = strtotime($start);
		if ($end > "") $end = strtotime($end);
		//save the findings
		$found=array();
		if ($email.$login.$start.$end > ""){
			if ($email!="") {
				$email = charrestore_gethash($email); // search for the hash
			}
			$path = charrestore_getstorepath();
			output("Chars saved in %s`n`n",$path);
			$d = dir($path);
			$count = 0;
			//fetch them to sort the directory 
			while (($entry = $d->read())!==false){
				$new[]=$entry;
			}
			sort($new);
			//			while (($entry = $d->read())!==false){
			foreach ($new as $entry) {
				$e = explode("|",$entry);
				if (count($e)<2) continue;
				$name = str_replace("_"," ",$e[0]);
				if (count($e)==2) 
					$date = strtotime($e[1]);
				else 
					$date = strtotime($e[2]);
				if ($start > ""){
					if ($date < $start) continue;
				}
				if ($end > ""){
					if ($date > $end) continue;
				}
				if ($login > "") {
					if (strpos(strtolower($name),strtolower($login))===false)
						continue;
				}
				if ($email > "") {
					//read the file
					$content=file_get_contents($path."/".$entry);
					//unpack
					$content=unserialize($content);
					$email_acc=$content['account']['emailaddress'];
					if (strpos(strtolower($email_acc),strtolower($email))===false)
						continue;
				} else {
					//found one hit, now read the file - please leave this last entry
					//read the file
					$content=file_get_contents($path."/".$entry);
					//unpack
					$content=unserialize($content);
					$email_acc=$content['account']['emailaddress'];
					$acctid_acc=$content['account']['acctid'];
					$dks_acc=$content['account']['dragonkills'];
				}
				$count++;
				$found[$name."--".$date]=array("name"=>$name,"entry"=>$entry,"date"=>$date,"email"=>$email_acc,"acctid"=>$acctid_acc,"dragonkills"=>$dks_acc);
				//				rawoutput("<a href='runmodule.php?module=charrestore&op=beginrestore&file=".rawurlencode($entry)."'>$name</a> (".date("M d, Y",$date).")<br>");
				//				addnav("","runmodule.php?module=charrestore&op=beginrestore&file=".rawurlencode($entry));
			}
			if ($count == 0) {
				output("No characters matching the specified criteria were found.");
			} else {
				//sort and output the findings
				ksort($found);
				foreach ($found as $row) {
					rawoutput("<a href='runmodule.php?module=charrestore&op=beginrestore&file=".rawurlencode($row['entry'].$retnav)."'>".$row['name']."</a> (".date("M d, Y",$row['date']).") (".$row['email'].") ".$row['dragonkills']." DKs ID ".$row['acctid']."<br>");
					addnav("","runmodule.php?module=charrestore&op=beginrestore&file=".rawurlencode($row['entry']).$retnav);

				}
			}
		}
		} elseif (httpget('op')=="hashtest") {
			output("Emailaddress to convert:`n");
			rawoutput("<form action='runmodule.php?module=charrestore&op=hashtest$retnav' method='POST'>");
			addnav("","runmodule.php?module=charrestore&op=hashtest".$retnav);
			rawoutput("<table><tr><td>");
			output("String: ");
			rawoutput("<input name='teststring'\"><br>");
			rawoutput("</td><td></tr></table>");
			$submit = translate_inline("Submit");
			rawoutput("<input type='submit' value='$submit' class='button'>");	
			rawoutput("</form>");
			output("Hashed String: `\$%s",charrestore_gethash(httppost('teststring')));
		} elseif (httpget("op")=="beginrestore"){
			$user = unserialize(join("",file(charrestore_getstorepath().httpget("file"))));
			$sql = "SELECT count(acctid) AS c FROM ".db_prefix("accounts")." WHERE login='{$user['account']['login']}'";
			$result = db_query($sql);
			$row = db_fetch_assoc($result);
			rawoutput("<form action='runmodule.php?module=charrestore&op=finishrestore&file=".rawurlencode(stripslashes(httpget("file"))).$retnav."' method='POST'>");
			addnav("","runmodule.php?module=charrestore&op=finishrestore&file=".rawurlencode(stripslashes(httpget("file"))).$retnav);
			if ($row['c'] > 0){
				output("`\$The user's login conflicts with an existing login in the system.");
				output("You will have to provide a new one, and you should probably think about giving them a new name after the restore.`n");
				output("`^New Login: ");
				rawoutput("<input name='newlogin'><br>");
			}

			$sql="SELECT count(acctid) as c FROM ".db_prefix('accounts')." WHERE acctid=".$user['account']['acctid'];
			$result=db_query($sql);
			$row=db_fetch_assoc($result);
			if ($row['c']>0) {
				output("`\$The user has already a char here ... you want to maybe restore an older version of it.`n`nYou have to DELETE it first in order to restore this one.");
				page_footer();	
			}

			$yes = translate_inline("Do the restore");
			rawoutput("<input type='submit' value='$yes' class='button'>");

			output("`n`#Some user info:`0`n");
			$vars = array(
					"login"=>"Login",
					"name"=>"Name",
					"acctid"=>"Account ID",
					"laston"=>"Last On",
					"emailaddress"=>"Email Passcode",
					"dragonkills"=>"DKs",
					"level"=>"Level",
					"gentimecount"=>"Total hits",
				     );
			foreach ($vars as $key=>$val) {
				output("`^$val: `#%s`n",$user['account'][$key]);
			}
			rawoutput("<input type='submit' value='$yes' class='button'>");
			rawoutput("</form>");

		}elseif (httpget("op")=="finishrestore"){
			$newlogin=(httppost('newlogin')>''?httppost('newlogin'):$user['account']['login']);
			$user = unserialize(join("",file(charrestore_getstorepath().httpget("file"))));
			$sql = "SELECT acctid FROM ".db_prefix("accounts")." WHERE login='$newlogin'";
			$result = db_query($sql);
			$count = db_num_rows($result);
			if ($count > 0){
				$ids=array();
				while ($row=db_fetch_assoc($result)) {
					$ids[]=$row['acctid'];
				}
				$link="runmodule.php?module=charrestore&op=beginrestore&file=".rawurlencode(stripslashes(httpget("file")));
				output("Hm. Login '%s' seems to exist already as Account-ID %s. If you want to go on, you need to give out a new login <a href='$link'>here</a>",$newlogin,implode(",",$ids),true);
			}else{
				if (httppost("newlogin") > "") $user['account']['login'] = httppost('newlogin');
				$sql = "DESCRIBE ".db_prefix("accounts");
				$result = db_query($sql);
				$known_columns = array();
				while ($row = db_fetch_assoc($result)){
					$known_columns[$row['Field']] = true;
				}

				//sanity fill ups due to empty values and no default values set
				$default_fill = array(
					"allowednavs",
					);
				foreach ($default_fill as $defval) {
					if (!in_array($defval,$user['account'])) {
						//set
						$known_columns[$defval]= true;
						$user['account'][$defval]="";
					}

				}
				//end

				$keys = array();
				$vals = array();

				foreach ($user['account'] as $key=>$val) {
					if ($key=="laston"){
						array_push($keys,$key);
						array_push($vals,"'".date("Y-m-d H:i:s",strtotime("-1 day"))."'");
					}elseif (! isset($known_columns[$key])){
						output("`2Dropping the column `^%s`n",$key);
					}else{
						if($val=="0000-00-00 00:00:00") $val = DATETIME_DATEMIN; // fix old time stamps
						array_push($keys,$key);
						array_push($vals,"'".addslashes($val)."'");
					}
				}
				$sql = "INSERT INTO ".db_prefix("accounts")." (\n".join("\t,\n",$keys).") VALUES (\n".join("\t,\n",$vals).")";
				db_query($sql);
				$id = db_insert_id();
				if ($id > 0){
					if ($session['user']['superuser'] & SU_EDIT_USERS == SU_EDIT_USERS) { 
						addnav("Edit the restored user","user.php?op=edit&userid=$id".$retnav);
					}
					if ($id != $user['account']['acctid']){
						output("`^The account was restored, though the account ID was not preserved; things such as news, mail, comments, debuglog, and other items associated with this account that were not stored as part of the snapshot have lost their association.");
						output("The original ID was `&%s`^, and the new ID is `&%s`^.",$user['account']['acctid'],$id);
						output("The most common cause of this problem is another account already present with the same ID.");
						output("Did you do a restore of an already existing account?  If so, the existing account was not overwritten.`n");
					}else{
						output("`#The account was restored.`n");
					}
					output("`#Now working on module preferences.`n");
					while (list($modulename,$values)=each($user['prefs'])){
						output("`3Module: `2%s`3...`n",$modulename);
						if (is_module_installed($modulename)){
							while (list($prefname,$value)=each($values)){
								set_module_pref($prefname,$value,$modulename,$id);
							}
						}else{
							output("`\$Skipping prefs for module `^%s`\$ because this module is not currently installed.`n",$modulename);
						}
					}
					output("`#The preferences were restored.`n");
					// sadly not possible anymore. we do not know the emailaddress (data privacy regulation)
					/*					$targetid=$user['account']['acctid'];
										$targetmail=$user['account']['emailaddress'];
										$subject=translate_mail(array("Your character %s",sanitize($user['account']['login'])),$targetid);
										$body=translate_mail(array(
										"Your character %s has been restored. You may now login to our site and the restored character.`n`nIf you do not remember your password, use the 'Forgotten Password' link on the homepage to get login and change it.`n`nRegards,\nStaff",
										sanitize($user['account']['login'])),
										$targetid);
										$body = str_replace("`n","\n",$body);
										if (get_module_setting('notifymail')) {
										$result=charrestore_sendmail($targetmail,$body,$subject,get_module_setting('adminmail'),get_module_setting('adminname'));
										if ($result) {
										output("`\$The notification message has been sent!`n");
										} else {
										output("`\$There has been an error! The notification message was NOT sent!`n");
										}
										}
					 */

				}else{
					output("`\$Something funky has happened, preventing this account from correctly being created.");
					output("I'm sorry, you may have to recreate this account by hand.");
					output("The SQL I tried was:`n");
					rawoutput("<pre>".htmlentities($sql, ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."</pre>");
				}
			}
		} elseif (httpget('op')=="hashconvert") {
			$convert = (int)httpget('convert'); // == 1 if we want to convert
			$path = charrestore_getstorepath();
			$d = dir($path);
			$count = 0;
			//fetch them to sort the directory 
			while (($entry = $d->read())!==false){
				$new[]=$entry;
			}
			sort($new);
			$totalcount = 0;
			//			while (($entry = $d->read())!==false){
			foreach ($new as $entry) {
				$e = explode("|",$entry);
				if (count($e)<2) continue;
				$totalcount++;
				$name = str_replace("_"," ",$e[0]);
				if (count($e)==2) 
					$date = strtotime($e[1]);
				else 
					$date = strtotime($e[2]);
				//read the file
				$content=file_get_contents($path."/".$entry);
				//unpack
				$content=unserialize($content);
				$email_acc=$content['account']['emailaddress'];
				if (strlen($email_acc)==strlen(charrestore_gethash('test')) && strpos($email_acc,'@')===false) {
					continue; //already hashed and salted or superlong email
				} else {
					//found one hit, now count up and convert if necessary
					$dks_acc=$content['account']['dragonkills'];
					if ($convert==1) {
						//convert this one
						$content['account']['emailaddress']=charrestore_gethash($email_acc);
						$fp = @fopen($path."/".$entry,"w+");
						if ($fp){
							if (fwrite($fp,
										serialize($content)
								  )!==false){
								$failure=false;
							}else{
								$failure=true;
							}
							fclose($fp);
						}
						if ($failure==true || !is_writeable($parth."/".$entry)) {
							output("Could not be written: %s`n",$entry);
						}
					}
					$count++;
					$found[$name."--".$date]=array("name"=>$name,"entry"=>$entry,"date"=>$date,"email"=>$email_acc,"acctid"=>$acctid_acc,"dragonkills"=>$dks_acc); //not used but collected
				}
			}
			if ($convert==1) {
				output("`q%s Chars saved in total. `n`x%s Chars have been converted.`n`n",$totalcount,$count);
			} else {
				output("`q%s Chars saved in total. `n",$totalcount);
			}
			addnav("Convert");
			if ($count>0) {
				// we need to convert
				output("`2%s Chars have `\$NO SALTED PASSWORD HASH`2 and should be converted now.`n`n",$count);
				output("`\$In case you choose to convert, we advise to backup your data first in case something goes awry during this!!!");
				addnav("Convert now","runmodule.php?module=charrestore&op=hashconvert&convert=1");
			} else {
				output("`xNo conversion necessary. All emails are salted and hashed.");
				addnav("Convert now","");
			}
		}
		page_footer();
		}

		function charrestore_sendmail($to, $body, $subject, $fromaddress, $fromname, $attachments=false)
		{
			require_once("lib/sendmail.php");
			$to_array=array($to=>$to);
			$from_array=array($fromaddress=>$fromname);
			$cc_array=false;
			$mail_sent = send_email($to_array,$body,$subject,$from_array,$cc_array,"text/html");
			return $mail_sent;
		}

		function charrestore_gethash($value) {
			return hash('sha512',$value.get_module_setting('email_hash_salt','charrestore'));
		}
