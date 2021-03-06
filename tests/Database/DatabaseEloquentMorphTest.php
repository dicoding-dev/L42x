<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseEloquentMorphTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testMorphOneSetsProperConstraints()
    {
        $relation = $this->getOneRelation();
    }


	public function testMorphOneEagerConstraintsAreProperlyAdded()
	{
		$relation = $this->getOneRelation();
		$relation->getQuery()->shouldReceive('whereIn')->once()->with('table.morph_id', [1, 2]);
		$relation->getQuery()->shouldReceive('where')->once()->with('table.morph_type', get_class($relation->getParent()));

		$model1 = new EloquentMorphResetModelStub;
		$model1->id = 1;
		$model2 = new EloquentMorphResetModelStub;
		$model2->id = 2;
		$relation->addEagerConstraints([$model1, $model2]);
	}


	/**
	 * Note that the tests are the exact same for morph many because the classes share this code...
	 * Will still test to be safe.
	 */
	public function testMorphManySetsProperConstraints()
	{
		$relation = $this->getManyRelation();
	}


	public function testMorphManyEagerConstraintsAreProperlyAdded()
	{
		$relation = $this->getManyRelation();
		$relation->getQuery()->shouldReceive('whereIn')->once()->with('table.morph_id', [1, 2]);
		$relation->getQuery()->shouldReceive('where')->once()->with('table.morph_type', get_class($relation->getParent()));

		$model1 = new EloquentMorphResetModelStub;
		$model1->id = 1;
		$model2 = new EloquentMorphResetModelStub;
		$model2->id = 2;
		$relation->addEagerConstraints([$model1, $model2]);
	}


	public function testCreateFunctionOnMorph()
	{
		// Doesn't matter which relation type we use since they share the code...
		$relation = $this->getOneRelation();
		$created = m::mock(Model::class);
		$created->shouldReceive('setAttribute')->once()->with('morph_id', 1);
		$created->shouldReceive('setAttribute')->once()->with('morph_type', get_class($relation->getParent()));
		$relation->getRelated()->shouldReceive('newInstance')->once()->with(['name' => 'taylor'])->andReturn($created);
		$created->shouldReceive('save')->once()->andReturn(true);

		$this->assertEquals($created, $relation->create(['name' => 'taylor']));
	}


	protected function getOneRelation()
	{
		$builder = m::mock(Builder::class);
		$builder->shouldReceive('where')->once()->with('table.morph_id', '=', 1);
		$related = m::mock(Model::class);
		$builder->shouldReceive('getModel')->andReturn($related);
		$parent = m::mock(Model::class);
		$parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
		$parent->shouldReceive('getMorphClass')->andReturn(get_class($parent));
		$builder->shouldReceive('where')->once()->with('table.morph_type', get_class($parent));
		return new MorphOne($builder, $parent, 'table.morph_type', 'table.morph_id', 'id');
	}


	protected function getManyRelation()
	{
		$builder = m::mock(Builder::class);
		$builder->shouldReceive('where')->once()->with('table.morph_id', '=', 1);
		$related = m::mock(Model::class);
		$builder->shouldReceive('getModel')->andReturn($related);
		$parent = m::mock(Model::class);
		$parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
		$parent->shouldReceive('getMorphClass')->andReturn(get_class($parent));
		$builder->shouldReceive('where')->once()->with('table.morph_type', get_class($parent));
		return new MorphMany($builder, $parent, 'table.morph_type', 'table.morph_id', 'id');
	}

}


class EloquentMorphResetModelStub extends Illuminate\Database\Eloquent\Model {}


class EloquentMorphResetBuilderStub extends Illuminate\Database\Eloquent\Builder {
	public function __construct() { $this->query = new EloquentRelationQueryStub; }
	public function getModel() { return new EloquentMorphResetModelStub; }
	public function isSoftDeleting() { return false; }
}


class EloquentMorphQueryStub extends Illuminate\Database\Query\Builder {
	public function __construct() {}
}
