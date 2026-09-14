<?php

use Illuminate\Contracts\Pipeline\Pipeline as PipelineContract;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\MacroableTrait;
use L4\Tests\BackwardCompatibleTestCase;

class SupportMacroableConditionablePipelineTest extends BackwardCompatibleTestCase
{
    public function testMacroableRegistersAndCalls(): void
    {
        $o = new class { use Macroable; };
        $o::macro('shout', fn ($s) => strtoupper($s));

        $this->assertTrue($o::hasMacro('shout'));
        $this->assertEquals('HI', $o->shout('hi'));
    }

    public function testMacroableClosureBindsToInstance(): void
    {
        $o = new class { public $v = 41; use Macroable; };
        $o::macro('next', fn () => $this->v + 1);

        $this->assertEquals(42, $o->next());
    }

    public function testMacroableTraitAliasStillWorks(): void
    {
        $o = new class { use MacroableTrait; };
        $o::macro('ok', fn () => 'legacy');

        $this->assertEquals('legacy', $o->ok());
    }

    public function testConditionableWhenAndUnless(): void
    {
        $o = new class { public $v = 0; use Conditionable; public function bump() { $this->v++; return $this; } };

        $o->when(true, fn ($x) => $x->bump())
          ->when(false, fn ($x) => $x->bump())
          ->unless(false, fn ($x) => $x->bump());

        $this->assertEquals(2, $o->v);
    }

    public function testPipelinePassesThroughStack(): void
    {
        $result = (new Pipeline)
            ->send('a')
            ->through([
                fn ($passable, $next) => $next($passable . '-b'),
                fn ($passable, $next) => $next($passable . '-c'),
            ])
            ->then(fn ($passable) => $passable . '-end');

        $this->assertInstanceOf(PipelineContract::class, new Pipeline);
        $this->assertEquals('a-b-c-end', $result);
    }
}
