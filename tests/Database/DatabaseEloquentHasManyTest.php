<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseEloquentHasManyTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testCreateMethodProperlyCreatesNewModel()
    {
        $relation = $this->getRelation();
        $created = $this->getMock(Model::class, ['save', 'getKey', 'setAttribute']);
        $created->expects($this->once())->method('save')->willReturn(true);
		$relation->getRelated()->shouldReceive('newInstance')->once()->with(['name' => 'taylor'])->andReturn($created);
		$created->expects($this->once())->method('setAttribute')->with('foreign_key', 1);

		$this->assertEquals($created, $relation->create(['name' => 'taylor']));
	}


	public function testUpdateMethodUpdatesModelsWithTimestamps()
	{
		$relation = $this->getRelation();
		$relation->getRelated()->shouldReceive('usesTimestamps')->once()->andReturn(true);
		$relation->getRelated()->shouldReceive('freshTimestamp')->once()->andReturn($carbon = new Carbon());
		$relation->getRelated()->shouldReceive('getUpdatedAtColumn')->andReturn('updated_at');
		$relation->getQuery()->shouldReceive('update')->once()->with(['foo' => 'bar', 'updated_at' => $carbon])->andReturn('results');

		$this->assertEquals('results', $relation->update(['foo' => 'bar']));
	}


	public function testRelationIsProperlyInitialized()
	{
		$relation = $this->getRelation();
		$model = m::mock(Model::class);
		$relation->getRelated()->shouldReceive('newCollection')->andReturnUsing(function($array = []) { return new Collection($array); });
		$model->shouldReceive('setRelation')->once()->with('foo', m::type(Collection::class));
		$models = $relation->initRelation([$model], 'foo');

		$this->assertEquals([$model], $models);
	}


	public function testEagerConstraintsAreProperlyAdded()
	{
		$relation = $this->getRelation();
		$relation->getQuery()->shouldReceive('whereIn')->once()->with('table.foreign_key', [1, 2]);
		$model1 = new EloquentHasManyModelStub;
		$model1->id = 1;
		$model2 = new EloquentHasManyModelStub;
		$model2->id = 2;
		$relation->addEagerConstraints([$model1, $model2]);
	}


	public function testModelsAreProperlyMatchedToParents()
	{
		$relation = $this->getRelation();

		$result1 = new EloquentHasManyModelStub;
		$result1->foreign_key = 1;
		$result2 = new EloquentHasManyModelStub;
		$result2->foreign_key = 2;
		$result3 = new EloquentHasManyModelStub;
		$result3->foreign_key = 2;

		$model1 = new EloquentHasManyModelStub;
		$model1->id = 1;
		$model2 = new EloquentHasManyModelStub;
		$model2->id = 2;
		$model3 = new EloquentHasManyModelStub;
		$model3->id = 3;

		$relation->getRelated()->shouldReceive('newCollection')->andReturnUsing(function($array) { return new Collection($array); });
		$models = $relation->match([$model1, $model2, $model3], new Collection([$result1, $result2, $result3]), 'foo');

		$this->assertEquals(1, $models[0]->foo[0]->foreign_key);
		$this->assertCount(1, $models[0]->foo);
		$this->assertEquals(2, $models[1]->foo[0]->foreign_key);
		$this->assertEquals(2, $models[1]->foo[1]->foreign_key);
		$this->assertCount(2, $models[1]->foo);
		$this->assertEmpty($models[2]->foo);
	}


	protected function getRelation()
	{
		$builder = m::mock(Builder::class);
		$builder->shouldReceive('where')->with('table.foreign_key', '=', 1);
		$related = m::mock(Model::class);
		$builder->shouldReceive('getModel')->andReturn($related);
		$parent = m::mock(Model::class);
		$parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
		$parent->shouldReceive('getCreatedAtColumn')->andReturn('created_at');
		$parent->shouldReceive('getUpdatedAtColumn')->andReturn('updated_at');
		return new HasMany($builder, $parent, 'table.foreign_key', 'id');
	}

}

class EloquentHasManyModelStub extends Illuminate\Database\Eloquent\Model {
	public $foreign_key = 'foreign.value';
}
