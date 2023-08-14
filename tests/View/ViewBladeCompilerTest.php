<?php

use Illuminate\View\Compilers\BladeCompiler;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class ViewBladeCompilerTest extends BackwardCompatibleTestCase
{

    private BladeCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new BladeCompiler($this->getFiles(), __DIR__);
    }

    protected function tearDown(): void
    {
        m::close();
    }


    public function testIsExpiredReturnsTrueIfCompiledFileDoesntExist()
    {
        $compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
        $files->shouldReceive('exists')->once()->with(__DIR__ . '/' . md5('foo'))->andReturn(false);
        $this->assertTrue($compiler->isExpired('foo'));
	}


	public function testIsExpiredReturnsTrueIfCachePathIsNull()
	{
		$compiler = new BladeCompiler($files = $this->getFiles(), null);
		$files->shouldReceive('exists')->never();
		$this->assertTrue($compiler->isExpired('foo'));
	}


	public function testIsExpiredReturnsTrueWhenModificationTimesWarrant()
	{
		$compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
		$files->shouldReceive('exists')->once()->with(__DIR__.'/'.md5('foo'))->andReturn(true);
		$files->shouldReceive('lastModified')->once()->with('foo')->andReturn(100);
		$files->shouldReceive('lastModified')->once()->with(__DIR__.'/'.md5('foo'))->andReturn(0);
		$this->assertTrue($compiler->isExpired('foo'));
	}


	public function testCompilePathIsProperlyCreated()
	{
		$compiler = new BladeCompiler($this->getFiles(), __DIR__);
		$this->assertEquals(__DIR__.'/'.md5('foo'), $compiler->getCompiledPath('foo'));
	}


	public function testCompileCompilesFileAndReturnsContents()
	{
		$compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
		$files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
		$files->shouldReceive('put')->once()->with(__DIR__.'/'.md5('foo'), 'Hello World');
		$compiler->compile('foo');
	}


	public function testCompileCompilesAndGetThePath()
	{
		$compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
		$files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
		$files->shouldReceive('put')->once()->with(__DIR__.'/'.md5('foo'), 'Hello World');
		$compiler->compile('foo');
		$this->assertEquals('foo', $compiler->getPath());
	}


	public function testCompileSetAndGetThePath()
	{
		$compiler = new BladeCompiler($files = $this->getFiles(), __DIR__);
		$compiler->setPath('foo');
		$this->assertEquals('foo', $compiler->getPath());
	}


	public function testCompileDoesntStoreFilesWhenCachePathIsNull()
	{
		$compiler = new BladeCompiler($files = $this->getFiles(), null);
		$files->shouldReceive('get')->once()->with('foo')->andReturn('Hello World');
		$files->shouldReceive('put')->never();
		$compiler->compile('foo');
	}


	public function testEchosAreCompiled()
	{
		$this->assertEquals('<?php echo e($name); ?>', $this->compiler->compileString('{{{$name}}}'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('{{$name}}'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('{{ $name }}'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('{{
			$name
		}}'));
		$this->assertEquals("<?php echo \$name; ?>\n\n", $this->compiler->compileString("{{ \$name }}\n"));
		$this->assertEquals("<?php echo \$name; ?>\r\n\r\n", $this->compiler->compileString("{{ \$name }}\r\n"));
		$this->assertEquals("<?php echo \$name; ?>\n\n", $this->compiler->compileString("{{ \$name }}\n"));
		$this->assertEquals("<?php echo \$name; ?>\r\n\r\n", $this->compiler->compileString("{{ \$name }}\r\n"));

		$this->assertEquals('<?php echo isset($name) ? $name : "foo"; ?>', $this->compiler->compileString('{{ $name or "foo" }}'));
		$this->assertEquals('<?php echo isset($user->name) ? $user->name : "foo"; ?>', $this->compiler->compileString('{{ $user->name or "foo" }}'));
		$this->assertEquals('<?php echo isset($name) ? $name : "foo"; ?>', $this->compiler->compileString('{{$name or "foo"}}'));
		$this->assertEquals('<?php echo isset($name) ? $name : "foo"; ?>', $this->compiler->compileString('{{
			$name or "foo"
		}}'));

		$this->assertEquals('<?php echo isset($name) ? $name : \'foo\'; ?>', $this->compiler->compileString('{{ $name or \'foo\' }}'));
		$this->assertEquals('<?php echo isset($name) ? $name : \'foo\'; ?>', $this->compiler->compileString('{{$name or \'foo\'}}'));
		$this->assertEquals('<?php echo isset($name) ? $name : \'foo\'; ?>', $this->compiler->compileString('{{
			$name or \'foo\'
		}}'));

		$this->assertEquals('<?php echo isset($age) ? $age : 90; ?>', $this->compiler->compileString('{{ $age or 90 }}'));
		$this->assertEquals('<?php echo isset($age) ? $age : 90; ?>', $this->compiler->compileString('{{$age or 90}}'));
		$this->assertEquals('<?php echo isset($age) ? $age : 90; ?>', $this->compiler->compileString('{{
			$age or 90
		}}'));

		$this->assertEquals('<?php echo "Hello world or foo"; ?>', $this->compiler->compileString('{{ "Hello world or foo" }}'));
		$this->assertEquals('<?php echo "Hello world or foo"; ?>', $this->compiler->compileString('{{"Hello world or foo"}}'));
		$this->assertEquals('<?php echo $foo + $or + $baz; ?>', $this->compiler->compileString('{{$foo + $or + $baz}}'));
		$this->assertEquals('<?php echo "Hello world or foo"; ?>', $this->compiler->compileString('{{
			"Hello world or foo"
		}}'));

		$this->assertEquals('<?php echo \'Hello world or foo\'; ?>', $this->compiler->compileString('{{ \'Hello world or foo\' }}'));
		$this->assertEquals('<?php echo \'Hello world or foo\'; ?>', $this->compiler->compileString('{{\'Hello world or foo\'}}'));
		$this->assertEquals('<?php echo \'Hello world or foo\'; ?>', $this->compiler->compileString('{{
			\'Hello world or foo\'
		}}'));

		$this->assertEquals('<?php echo myfunc(\'foo or bar\'); ?>', $this->compiler->compileString('{{ myfunc(\'foo or bar\') }}'));
		$this->assertEquals('<?php echo myfunc("foo or bar"); ?>', $this->compiler->compileString('{{ myfunc("foo or bar") }}'));
		$this->assertEquals('<?php echo myfunc("$name or \'foo\'"); ?>', $this->compiler->compileString('{{ myfunc("$name or \'foo\'") }}'));
	}


	public function testEscapedWithAtEchosAreCompiled()
	{
		$this->assertEquals('{{$name}}', $this->compiler->compileString('@{{$name}}'));
		$this->assertEquals('{{ $name }}', $this->compiler->compileString('@{{ $name }}'));
		$this->assertEquals('{{
			$name
		}}',
		$this->compiler->compileString('@{{
			$name
		}}'));
		$this->assertEquals('{{ $name }}
			',
		$this->compiler->compileString('@{{ $name }}
			'));
	}


	public function testReversedEchosAreCompiled()
	{
		$this->compiler->setEscapedContentTags('{{', '}}');
		$this->compiler->setContentTags('{{{', '}}}');
		$this->assertEquals('<?php echo e($name); ?>', $this->compiler->compileString('{{$name}}'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('{{{$name}}}'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('{{{ $name }}}'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('{{{
			$name
		}}}'));
	}


	public function testExtendsAreCompiled()
	{
		$compiler = new BladeCompiler($this->getFiles(), __DIR__);
		$string = '@extends(\'foo\')
test';
		$expected = "test".PHP_EOL.'<?php echo $__env->make(\'foo\', array_except(get_defined_vars(), array(\'__data\', \'__path\')))->render(); ?>';
		$this->assertEquals($expected, $compiler->compileString($string));


		$compiler = new BladeCompiler($this->getFiles(), __DIR__);
		$string = '@extends(name(foo))'.PHP_EOL.'test';
		$expected = "test".PHP_EOL.'<?php echo $__env->make(name(foo), array_except(get_defined_vars(), array(\'__data\', \'__path\')))->render(); ?>';
		$this->assertEquals($expected, $compiler->compileString($string));
	}


	public function testPushIsCompiled()
	{
		$string = '@push(\'foo\')
test
@endpush';
		$expected = '<?php $__env->startSection(\'foo\'); ?>
test
<?php $__env->appendSection(); ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testStackIsCompiled()
	{
		$string = '@stack(\'foo\')';
		$expected = '<?php echo $__env->yieldContent(\'foo\'); ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testCommentsAreCompiled()
	{
		$string = '{{--this is a comment--}}';
		$expected = '<?php /*this is a comment*/ ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));


		$string = '{{--
this is a comment
--}}';
		$expected = '<?php /*
this is a comment
*/ ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testIfStatementsAreCompiled()
	{
		$string = '@if (name(foo(bar)))
breeze
@endif';
		$expected = '<?php if(name(foo(bar))): ?>
breeze
<?php endif; ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testElseStatementsAreCompiled()
	{
		$string = '@if (name(foo(bar)))
breeze
@else
boom
@endif';
		$expected = '<?php if(name(foo(bar))): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testElseIfStatementsAreCompiled()
	{
		$string = '@if(name(foo(bar)))
breeze
@elseif(boom(breeze))
boom
@endif';
		$expected = '<?php if(name(foo(bar))): ?>
breeze
<?php elseif(boom(breeze)): ?>
boom
<?php endif; ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testUnlessStatementsAreCompiled()
	{
		$string = '@unless (name(foo(bar)))
breeze
@endunless';
		$expected = '<?php if ( ! (name(foo(bar)))): ?>
breeze
<?php endif; ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testForelseStatementsAreCompiled()
	{
		$string = '@forelse ($this->getUsers() as $user)
breeze
@empty
empty
@endforelse';
		$expected = '<?php $__empty_1 = true; foreach($this->getUsers() as $user): $__empty_1 = false; ?>
breeze
<?php endforeach; if ($__empty_1): ?>
empty
<?php endif; ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testNestedForelseStatementsAreCompiled()
	{
		$string = '@forelse ($this->getUsers() as $user)
@forelse ($user->tags as $tag)
breeze
@empty
tag empty
@endforelse
@empty
empty
@endforelse';
		$expected = '<?php $__empty_1 = true; foreach($this->getUsers() as $user): $__empty_1 = false; ?>
<?php $__empty_2 = true; foreach($user->tags as $tag): $__empty_2 = false; ?>
breeze
<?php endforeach; if ($__empty_2): ?>
tag empty
<?php endif; ?>
<?php endforeach; if ($__empty_1): ?>
empty
<?php endif; ?>';
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testStatementThatContainsNonConsecutiveParanthesisAreCompiled()
	{
		$string = "Foo @lang(function_call('foo(blah)')) bar";
		$expected = "Foo <?php echo \Illuminate\Support\Facades\Lang::get(function_call('foo(blah)')); ?> bar";
		$this->assertEquals($expected, $this->compiler->compileString($string));
	}


	public function testIncludesAreCompiled()
	{
		$this->assertEquals('<?php echo $__env->make(\'foo\', array_except(get_defined_vars(), array(\'__data\', \'__path\')))->render(); ?>', $this->compiler->compileString('@include(\'foo\')'));
		$this->assertEquals('<?php echo $__env->make(name(foo), array_except(get_defined_vars(), array(\'__data\', \'__path\')))->render(); ?>', $this->compiler->compileString('@include(name(foo))'));
	}


	public function testShowEachAreCompiled()
	{
		$this->assertEquals('<?php echo $__env->renderEach(\'foo\', \'bar\'); ?>', $this->compiler->compileString('@each(\'foo\', \'bar\')'));
		$this->assertEquals('<?php echo $__env->renderEach(name(foo)); ?>', $this->compiler->compileString('@each(name(foo))'));
	}


	public function testYieldsAreCompiled()
	{
		$this->assertEquals('<?php echo $__env->yieldContent(\'foo\'); ?>', $this->compiler->compileString('@yield(\'foo\')'));
		$this->assertEquals('<?php echo $__env->yieldContent(\'foo\', \'bar\'); ?>', $this->compiler->compileString('@yield(\'foo\', \'bar\')'));
		$this->assertEquals('<?php echo $__env->yieldContent(name(foo)); ?>', $this->compiler->compileString('@yield(name(foo))'));
	}


	public function testShowsAreCompiled()
	{
		$this->assertEquals('<?php echo $__env->yieldSection(); ?>', $this->compiler->compileString('@show'));
	}


	public function testLanguageAndChoicesAreCompiled()
	{
		$this->assertEquals('<?php echo \Illuminate\Support\Facades\Lang::get(\'foo\'); ?>', $this->compiler->compileString("@lang('foo')"));
		$this->assertEquals('<?php echo \Illuminate\Support\Facades\Lang::choice(\'foo\', 1); ?>', $this->compiler->compileString("@choice('foo', 1)"));
	}


	public function testSectionStartsAreCompiled()
	{
		$this->assertEquals('<?php $__env->startSection(\'foo\'); ?>', $this->compiler->compileString('@section(\'foo\')'));
		$this->assertEquals('<?php $__env->startSection(name(foo)); ?>', $this->compiler->compileString('@section(name(foo))'));
	}


	public function testStopSectionsAreCompiled()
	{
		$this->assertEquals('<?php $__env->stopSection(); ?>', $this->compiler->compileString('@stop'));
	}


	public function testEndSectionsAreCompiled()
	{
		$this->assertEquals('<?php $__env->stopSection(); ?>', $this->compiler->compileString('@endsection'));
	}


	public function testAppendSectionsAreCompiled()
	{
		$this->assertEquals('<?php $__env->appendSection(); ?>', $this->compiler->compileString('@append'));
	}


	public function testCustomPhpCodeIsCorrectlyHandled()
	{
		$this->assertEquals('<?php if($test): ?> <?php @show(\'test\'); ?> <?php endif; ?>', $this->compiler->compileString("@if(\$test) <?php @show('test'); ?> @endif"));
	}


	public function testMixingYieldAndEcho()
	{
		$this->assertEquals('<?php echo $__env->yieldContent(\'title\'); ?> - <?php echo Config::get(\'site.title\'); ?>', $this->compiler->compileString("@yield('title') - {{Config::get('site.title')}}"));
	}


	public function testCustomExtensionsAreCompiled()
	{
		$this->compiler->extend(function($value) { return str_replace('foo', 'bar', $value); });
		$this->assertEquals('bar', $this->compiler->compileString('foo'));
	}


	public function testConfiguringContentTags()
	{
		$this->compiler->setContentTags('[[', ']]');
		$this->compiler->setEscapedContentTags('[[[', ']]]');

		$this->assertEquals('<?php echo e($name); ?>', $this->compiler->compileString('[[[ $name ]]]'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('[[ $name ]]'));
		$this->assertEquals('<?php echo $name; ?>', $this->compiler->compileString('[[
			$name
		]]'));
	}


	public function testExpressionsOnTheSameLine()
	{
		$this->assertEquals('<?php echo \Illuminate\Support\Facades\Lang::get(foo(bar(baz(qux(breeze()))))); ?> space () <?php echo \Illuminate\Support\Facades\Lang::get(foo(bar)); ?>', $this->compiler->compileString('@lang(foo(bar(baz(qux(breeze()))))) space () @lang(foo(bar))'));
	}


	public function testExpressionWithinHTML()
	{
		$this->assertEquals('<html <?php echo $foo; ?>>', $this->compiler->compileString('<html {{ $foo }}>'));
		$this->assertEquals('<html<?php echo $foo; ?>>', $this->compiler->compileString('<html{{ $foo }}>'));
		$this->assertEquals('<html <?php echo $foo; ?> <?php echo \Illuminate\Support\Facades\Lang::get(\'foo\'); ?>>', $this->compiler->compileString('<html {{ $foo }} @lang(\'foo\')>'));
	}


    public function testSelectedStatementsAreCompiled()
    {
        $string = '<input @selected(name(foo(bar)))/>';
        $expected = "<input <?php if(name(foo(bar))): echo 'selected'; endif; ?>/>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }


    public function testCheckedStatementsAreCompiled()
    {
        $string = '<input @checked(name(foo(bar)))/>';
        $expected = "<input <?php if(name(foo(bar))): echo 'checked'; endif; ?>/>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }


    public function testDisabledStatementsAreCompiled()
    {
        $string = '<button @disabled(name(foo(bar)))>Foo</button>';
        $expected = "<button <?php if(name(foo(bar))): echo 'disabled'; endif; ?>>Foo</button>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }


    public function testClassesAreConditionallyCompiledFromArray()
    {
        $string = "<span @class(['font-bold', 'mt-4', 'ml-2' => true, 'mr-2' => false])></span>";
        $expected = "<span class=\"<?php echo e(\Illuminate\Support\Arr::toCssClasses(['font-bold', 'mt-4', 'ml-2' => true, 'mr-2' => false])); ?>\"></span>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }


    public function testJsonIsCompiledWithSafeDefaultEncodingOptions()
    {
        $string = 'var foo = @json($var);';
        $expected = 'var foo = <?php echo json_encode($var, 15, 512) ?>;';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }


    public function testEnvStatementsAreCompiled()
    {
        $string = "@env('staging')
breeze
@else
boom
@endenv";
        $expected = "<?php if (\Illuminate\Foundation\Application::environment('staging')): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>";

		$this->assertEquals($expected, $this->compiler->compileString($string));
    }


    public function testEnvStatementsWithMultipleStringParamsAreCompiled()
    {
        $string = "@env('staging', 'production')
breeze
@else
boom
@endenv";
        $expected = "<?php if (\Illuminate\Foundation\Application::environment('staging', 'production')): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }


    public function testEnvStatementsWithArrayParamAreCompiled()
    {
        $string = "@env(['staging', 'production'])
breeze
@else
boom
@endenv";
        $expected = "<?php if (\Illuminate\Foundation\Application::environment(['staging', 'production'])): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }


	protected function getFiles()
	{
		return m::mock('Illuminate\Filesystem\Filesystem');
	}


	public function testRetrieveDefaultContentTags()
	{
		$this->assertEquals(['{{', '}}'], $this->compiler->getContentTags());
	}


	public function testRetrieveDefaultEscapedContentTags()
	{
		$this->assertEquals(['{{{', '}}}'], $this->compiler->getEscapedContentTags());
	}


	/**
	 * @dataProvider testGetTagsProvider()
	 */
	public function testSetAndRetrieveContentTags($openingTag, $closingTag)
	{
		$this->compiler->setContentTags($openingTag, $closingTag);
		$this->assertSame([$openingTag, $closingTag], $this->compiler->getContentTags());
	}


	/**
	 * @dataProvider testGetTagsProvider()
	 */
	public function testSetAndRetrieveEscapedContentTags($openingTag, $closingTag)
	{
		$this->compiler->setEscapedContentTags($openingTag, $closingTag);
		$this->assertSame([$openingTag, $closingTag], $this->compiler->getEscapedContentTags());
	}


	public function testGetTagsProvider()
	{
		return [
			['{{', '}}'],
			['{{{', '}}}'],
			['[[', ']]'],
			['[[[', ']]]'],
			['((', '))'],
			['(((', ')))'],
		];
	}

}
