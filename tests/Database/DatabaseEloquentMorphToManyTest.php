<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseEloquentMorphToManyTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('whereIn')->once()->with('taggables.taggable_id', [1, 2]);
        $relation->getQuery()->shouldReceive('where')->once()->with(
            'taggables.taggable_type',
            get_class($relation->getParent())
        );
		$model1 = new EloquentMorphToManyModelStub;
		$model1->id = 1;
		$model2 = new EloquentMorphToManyModelStub;
		$model2->id = 2;
		$relation->addEagerConstraints([$model1, $model2]);
	}


	public function testAttachInsertsPivotTableRecord(): void
    {
		$relation = $this->getMock(MorphToMany::class, ['touchIfTouching'], $this->getRelationArguments());
		$query = m::mock('stdClass');
		$query->shouldReceive('from')->once()->with('taggables')->andReturn($query);
		$query->shouldReceive('insert')->once()->with(
            [['taggable_id' => 1, 'taggable_type' => get_class($relation->getParent()), 'tag_id' => 2, 'foo' => 'bar']]
        )->andReturn(true);
		$relation->getQuery()->shouldReceive('getQuery')->andReturn($mockQueryBuilder = m::mock('StdClass'));
		$mockQueryBuilder->shouldReceive('newQuery')->once()->andReturn($query);
		$relation->expects($this->once())->method('touchIfTouching');

		$relation->attach(2, ['foo' => 'bar']);
	}


	public function testDetachRemovesPivotTableRecord(): void
    {
		$relation = $this->getMock(MorphToMany::class, ['touchIfTouching'], $this->getRelationArguments());
		$query = m::mock('stdClass');
		$query->shouldReceive('from')->once()->with('taggables')->andReturn($query);
		$query->shouldReceive('where')->once()->with('taggable_id', 1)->andReturn($query);
		$query->shouldReceive('where')->once()->with('taggable_type', get_class($relation->getParent()))->andReturn($query);
		$query->shouldReceive('whereIn')->once()->with('tag_id', [1, 2, 3]);
		$query->shouldReceive('delete')->once()->andReturn(true);
		$relation->getQuery()->shouldReceive('getQuery')->andReturn($mockQueryBuilder = m::mock('StdClass'));
		$mockQueryBuilder->shouldReceive('newQuery')->once()->andReturn($query);
		$relation->expects($this->once())->method('touchIfTouching');

		$this->assertTrue($relation->detach([1, 2, 3]));
	}


	public function testDetachMethodClearsAllPivotRecordsWhenNoIDsAreGiven(): void
    {
		$relation = $this->getMock(MorphToMany::class, ['touchIfTouching'], $this->getRelationArguments());
		$query = m::mock('stdClass');
		$query->shouldReceive('from')->once()->with('taggables')->andReturn($query);
		$query->shouldReceive('where')->once()->with('taggable_id', 1)->andReturn($query);
		$query->shouldReceive('where')->once()->with('taggable_type', get_class($relation->getParent()))->andReturn($query);
		$query->shouldReceive('whereIn')->never();
		$query->shouldReceive('delete')->once()->andReturn(true);
		$relation->getQuery()->shouldReceive('getQuery')->andReturn($mockQueryBuilder = m::mock('StdClass'));
		$mockQueryBuilder->shouldReceive('newQuery')->once()->andReturn($query);
		$relation->expects($this->once())->method('touchIfTouching');

		$this->assertTrue($relation->detach());
	}


	public function getRelation(): MorphToMany
    {
		[$builder, $parent] = $this->getRelationArguments();

		return new MorphToMany($builder, $parent, 'taggable', 'taggables', 'taggable_id', 'tag_id');
	}


	public function getRelationArguments():array
    {
        $parent = m::mock(Model::class);
		$parent->shouldReceive('getMorphClass')->andReturn(get_class($parent));
		$parent->shouldReceive('getKey')->andReturn(1);
		$parent->shouldReceive('getCreatedAtColumn')->andReturn('created_at');
		$parent->shouldReceive('getUpdatedAtColumn')->andReturn('updated_at');
		$parent->shouldReceive('getMorphClass')->andReturn(get_class($parent));

		$builder = m::mock(Builder::class);
		$related = m::mock(Model::class);
		$builder->shouldReceive('getModel')->andReturn($related);

		$related->shouldReceive('getTable')->andReturn('tags');
		$related->shouldReceive('getKeyName')->andReturn('id');
		$related->shouldReceive('getMorphClass')->andReturn(get_class($related));

		$builder->shouldReceive('join')->once()->with('taggables', 'tags.id', '=', 'taggables.tag_id');
		$builder->shouldReceive('where')->once()->with('taggables.taggable_id', '=', 1);
		$builder->shouldReceive('where')->once()->with('taggables.taggable_type', get_class($parent));

		return [$builder, $parent, 'taggable', 'taggables', 'taggable_id', 'tag_id', 'relation_name', false];
	}

}

class EloquentMorphToManyModelStub extends Illuminate\Database\Eloquent\Model {
	protected array $guarded = [];
}
