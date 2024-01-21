<?php

namespace Jaxon\Tests\TestRegistration;

use Jaxon\Jaxon;
use Jaxon\Exception\SetupException;
use Jaxon\Plugin\Request\CallableClass\CallableClassPlugin;
use Jaxon\Plugin\Request\CallableClass\CallableObject;
use Jaxon\Plugin\Request\CallableDir\CallableDirPlugin;
use PHPUnit\Framework\TestCase;

use function Jaxon\jaxon;
use function strlen;

class DirectoryTest extends TestCase
{
    /**
     * @var CallableDirPlugin
     */
    protected $xDirPlugin;

    /**
     * @var CallableClassPlugin
     */
    protected $xClassPlugin;

    /**
     * @throws SetupException
     */
    public function setUp(): void
    {
        jaxon()->setOption('core.prefix.class', 'Jxn');

        jaxon()->register(Jaxon::CALLABLE_DIR, __DIR__ . '/../src/dir', [
            'classes' => [
                'ClassC' => [
                    'protected' => ['methodCc'],
                ],
                'ClassD' => [
                    'excluded' => true,
                ],
            ],
        ]);

        $this->xDirPlugin = jaxon()->di()->getCallableDirPlugin();
        $this->xClassPlugin = jaxon()->di()->getCallableClassPlugin();
    }

    /**
     * @throws SetupException
     */
    public function tearDown(): void
    {
        jaxon()->reset();
        parent::tearDown();
    }

    public function testPluginName()
    {
        $this->assertEquals(Jaxon::CALLABLE_DIR, $this->xDirPlugin->getName());
    }

    /**
     * @throws SetupException
     */
    public function testCallableDirClass()
    {
        $xClassACallable = $this->xClassPlugin->getCallable('ClassA');
        $xClassBCallable = $this->xClassPlugin->getCallable('ClassB');
        $xClassCCallable = $this->xClassPlugin->getCallable('ClassC');
        $xClassDCallable = $this->xClassPlugin->getCallable('ClassD');
        // Test callables classes
        $this->assertEquals(CallableObject::class, get_class($xClassACallable));
        $this->assertEquals(CallableObject::class, get_class($xClassBCallable));
        $this->assertEquals(CallableObject::class, get_class($xClassCCallable));
        $this->assertEquals(CallableObject::class, get_class($xClassDCallable));
        // Check export
        $this->assertFalse($xClassACallable->excluded());
        $this->assertFalse($xClassBCallable->excluded());
        $this->assertFalse($xClassCCallable->excluded());
        $this->assertTrue($xClassDCallable->excluded());
        // Check methods
        $this->assertTrue($xClassACallable->hasMethod('methodAa'));
        $this->assertTrue($xClassACallable->hasMethod('methodAb'));
        $this->assertFalse($xClassACallable->hasMethod('methodAc'));
    }

    /**
     * @throws SetupException
     */
    public function testCallableDirJsCode()
    {
        // The js is generated by the CallableClass plugin
        $this->assertEquals(32, strlen($this->xClassPlugin->getHash()));
        // $this->assertEquals('96d34bef2486b9b4b342ec292b4e8ed5', $this->xClassPlugin->getHash());
        $this->assertEquals(846, strlen($this->xClassPlugin->getScript()));
        // $this->assertEquals(file_get_contents(__DIR__ . '/../src/js/dir.js'), $this->xClassPlugin->getScript());
    }

    public function testClassNotFound()
    {
        // No callable for standard PHP functions.
        $this->expectException(SetupException::class);
        $this->xDirPlugin->getCallable('Simple');
    }

    public function testCallableDirIncorrectOption()
    {
        // Register a function with incorrect option
        $this->expectException(SetupException::class);
        jaxon()->register(Jaxon::CALLABLE_DIR, __DIR__ . '/../src', true);
    }

    public function testCallableDirIncorrectPath()
    {
        // Register a function with incorrect name
        $this->expectException(SetupException::class);
        jaxon()->register(Jaxon::CALLABLE_DIR, __DIR__ . '/../door');
    }
}
