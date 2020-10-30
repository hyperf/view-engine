<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace HyperfTest\ViewEngine;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\Event\EventDispatcher;
use Hyperf\Event\ListenerProvider;
use Hyperf\Utils\ApplicationContext;
use Hyperf\View\Mode;
use Hyperf\ViewEngine\Compiler\BladeCompiler;
use Hyperf\ViewEngine\Compiler\CompilerInterface;
use Hyperf\ViewEngine\Component\DynamicComponent;
use Hyperf\ViewEngine\ConfigProvider;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use Hyperf\ViewEngine\Contract\FinderInterface;
use Hyperf\ViewEngine\Contract\ViewInterface;
use Hyperf\ViewEngine\Factory\FinderFactory;
use Hyperf\ViewEngine\HyperfViewEngine;
use HyperfTest\ViewEngine\Stub\Alert;
use HyperfTest\ViewEngine\Stub\AlertSlot;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use function Hyperf\ViewEngine\view;

/**
 * @internal
 * @coversNothing
 */
class BladeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // create container
        $container = new Container(new DefinitionSource(array_merge([
            EventDispatcherInterface::class => EventDispatcher::class,
            ListenerProviderInterface::class => ListenerProvider::class,
        ], (new ConfigProvider())()['dependencies'])));

        ApplicationContext::setContainer($container);

        // register config
        $container->set(ConfigInterface::class, new Config([
            'view' => [
                'engine' => HyperfViewEngine::class,
                'mode' => Mode::SYNC,
                'config' => [
                    'view_path' => __DIR__ . '/storage/view/',
                    'cache_path' => __DIR__ . '/storage/cache/',
                ],
                'components' => [
                    'alert' => Alert::class,
                    'alert-slot' => AlertSlot::class,
                    'dynamic-component' => DynamicComponent::class,
                ],
                'namespaces' => [
                    'admin_config' => __DIR__ . '/admin',
                ],
            ],
        ]));

        // vendor 下的命令空间
        if (! file_exists(__DIR__ . '/storage/view/vendor/admin/simple_4.blade.php')) {
            @mkdir(__DIR__ . '/storage/view/vendor');
            @mkdir(__DIR__ . '/storage/view/vendor/admin_custom');
            @mkdir(__DIR__ . '/storage/view/vendor/admin_config');
            file_put_contents(__DIR__ . '/storage/view/vendor/admin_custom/simple_4.blade.php', 'from_vendor');
            file_put_contents(__DIR__ . '/storage/view/vendor/admin_config/simple_4.blade.php', 'from_vendor');
        }
    }

    public function testRegisterComponents()
    {
        $this->assertSame('success', trim((string) view('simple_8', ['message' => 'success'])));
        $this->assertSame('success', trim((string) view('simple_9', ['message' => 'success'])));
    }

    public function testRegisterNamespace()
    {
        $this->assertSame('from_admin', trim((string) view('admin_config::simple_3')));
        $this->assertSame('from_vendor', trim((string) view('admin_config::simple_4')));
    }

    public function testViewFunction()
    {
        $this->assertInstanceOf(FactoryInterface::class, view());
        $this->assertInstanceOf(ViewInterface::class, view('index'));
    }

    public function testHyperfEngine()
    {
        $engine = new HyperfViewEngine();

        $this->assertSame('<h1>fangx/view</h1>', $engine->render('index', [], []));
        $this->assertSame('<h1>fangx</h1>', $engine->render('home', ['user' => 'fangx'], []));
    }

    public function testRender()
    {
        $this->assertSame('<h1>fangx/view</h1>', trim((string) view('index')));
        $this->assertSame('<h1>fangx</h1>', trim((string) view('home', ['user' => 'fangx'])));
        // *.php
        $this->assertSame('fangx', trim((string) view('simple_1')));
        // *.html
        $this->assertSame('fangx', trim((string) view('simple_2')));
        // @extends & @yield & @section..@stop
        $this->assertSame('yield-content', trim((string) view('simple_5')));
        // @if..@else..@endif
        $this->assertSame('fangx', trim((string) view('simple_6')));
        // @{{ name }}
        $this->assertSame('{{ name }}', trim((string) view('simple_7')));
        // @json()
        $this->assertSame('{"email":"nfangxu@gmail.com","name":"fangx"}', trim((string) view('simple_10')));
    }

    public function testUseNamespace()
    {
        $finder = ApplicationContext::getContainer()->get(FinderInterface::class);
        $factory = new FinderFactory();
        $factory->addNamespace($finder, 'admin_custom', __DIR__ . '/admin');

        $this->assertSame('from_admin', trim((string) view('admin_custom::simple_3')));
        $this->assertSame('from_vendor', trim((string) view('admin_custom::simple_4')));
    }

    public function testComponent()
    {
        /** @var BladeCompiler $compiler */
        $compiler = ApplicationContext::getContainer()
            ->get(CompilerInterface::class);

        $compiler->component(Alert::class, 'alert');
        $compiler->component(AlertSlot::class, 'alert-slot');

        $this->assertSame('success', trim((string) view('simple_8', ['message' => 'success'])));
        $this->assertSame('success', trim((string) view('simple_9', ['message' => 'success'])));
    }

    public function testDynamicComponent()
    {
        /** @var BladeCompiler $compiler */
        $compiler = ApplicationContext::getContainer()
            ->get(CompilerInterface::class);

        $compiler->component(Alert::class, 'alert');
        $compiler->component(AlertSlot::class, 'alert-slot');

        $this->assertSame('ok', trim((string) view('simple_11', ['componentName' => 'alert', 'message' => 'ok'])));
    }
}
