<?php

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Events\Dispatcher;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseEloquentModelTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();

        Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
        Carbon::resetToStringFormat();
    }


    public function testAttributeManipulation()
    {
		$model = new EloquentModelStub;
		$model->name = 'foo';
		$this->assertEquals('foo', $model->name);
		$this->assertTrue(isset($model->name));
		unset($model->name);
		$this->assertFalse(isset($model->name));

		// test mutation
		$model->list_items = ['name' => 'taylor'];
		$this->assertEquals(['name' => 'taylor'], $model->list_items);
		$attributes = $model->getAttributes();
		$this->assertEquals(json_encode(['name' => 'taylor']), $attributes['list_items']);
	}


	public function testDirtyAttributes()
	{
		$model = new EloquentModelStub(['foo' => '1', 'bar' => 2, 'baz' => 3]);
		$model->syncOriginal();
		$model->foo = 1;
		$model->bar = 20;
		$model->baz = 30;

		$this->assertTrue($model->isDirty());
		$this->assertFalse($model->isDirty('foo'));
		$this->assertTrue($model->isDirty('bar'));
		$this->assertTrue($model->isDirty('foo', 'bar'));
		$this->assertTrue($model->isDirty(['foo', 'bar']));
	}


	public function testCalculatedAttributes()
	{
		$model = new EloquentModelStub;
		$model->password = 'secret';
		$attributes = $model->getAttributes();

		// ensure password attribute was not set to null
		$this->assertFalse(array_key_exists('password', $attributes));
		$this->assertEquals('******', $model->password);
		$this->assertEquals('5ebe2294ecd0e0f08eab7690d2a6ee69', $attributes['password_hash']);
		$this->assertEquals('5ebe2294ecd0e0f08eab7690d2a6ee69', $model->password_hash);
	}


	public function testNewInstanceReturnsNewInstanceWithAttributesSet()
	{
		$model = new EloquentModelStub;
		$instance = $model->newInstance(['name' => 'taylor']);
		$this->assertInstanceOf('EloquentModelStub', $instance);
		$this->assertEquals('taylor', $instance->name);
	}


	public function testHydrateCreatesCollectionOfModels()
	{
		$data = [['name' => 'Taylor'], ['name' => 'Otwell']];
		$collection = EloquentModelStub::hydrate($data);

		$this->assertInstanceOf(Collection::class, $collection);
		$this->assertCount(2, $collection);
		$this->assertInstanceOf('EloquentModelStub', $collection[0]);
		$this->assertInstanceOf('EloquentModelStub', $collection[1]);
		$this->assertEquals('Taylor', $collection[0]->name);
		$this->assertEquals('Otwell', $collection[1]->name);
	}


	public function testHydrateRawMakesRawQuery()
	{
		$collection = EloquentModelHydrateRawStub::hydrateRaw('SELECT ?', ['foo']);
		$this->assertEquals('hydrated', $collection);
	}


	public function testCreateMethodSavesNewModel()
	{
		$_SERVER['__eloquent.saved'] = false;
		$model = EloquentModelSaveStub::create(['name' => 'taylor']);
		$this->assertTrue($_SERVER['__eloquent.saved']);
		$this->assertEquals('taylor', $model->name);
	}


	public function testFindMethodCallsQueryBuilderCorrectly()
	{
		$result = EloquentModelFindStub::find(1);
		$this->assertEquals('foo', $result);
	}


	public function testFindMethodUseWritePdo()
	{
		$result =  EloquentModelFindWithWritePdoStub::onWriteConnection()->find(1);
	}


    public function testFindOrFailMethodThrowsModelNotFoundException()
    {
        $this->expectException(Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $result = EloquentModelFindNotFoundStub::findOrFail(1);
    }


	public function testFindMethodWithArrayCallsQueryBuilderCorrectly()
	{
		$result = EloquentModelFindManyStub::find([1, 2]);
		$this->assertEquals('foo', $result);
	}


	public function testDestroyMethodCallsQueryBuilderCorrectly()
	{
		$result = EloquentModelDestroyStub::destroy(1, 2, 3);
	}


	public function testWithMethodCallsQueryBuilderCorrectly()
	{
		$result = EloquentModelWithStub::with('foo', 'bar');
		$this->assertEquals('foo', $result);
	}


	public function testWithMethodCallsQueryBuilderCorrectlyWithArray()
	{
		$result = EloquentModelWithStub::with(['foo', 'bar']);
		$this->assertEquals('foo', $result);
	}


	public function testUpdateProcess()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes', 'updateTimestamps']);
		$query = m::mock(Builder::class);
		$query->shouldReceive('where')->once()->with('id', '=', 1);
		$query->shouldReceive('update')->once()->with(['name' => 'taylor']);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->expects($this->once())->method('updateTimestamps');
		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until')->once()->with('eloquent.saving: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('until')->once()->with('eloquent.updating: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('fire')->once()->with('eloquent.updated: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('fire')->once()->with('eloquent.saved: '.get_class($model), $model)->andReturn(true);

		$model->id = 1;
		$model->foo = 'bar';
		// make sure foo isn't synced so we can test that dirty attributes only are updated
		$model->syncOriginal();
		$model->name = 'taylor';
		$model->exists = true;
		$this->assertTrue($model->save());
	}


	public function testUpdateProcessDoesntOverrideTimestamps()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes']);
		$query = m::mock(Builder::class);
		$query->shouldReceive('where')->once()->with('id', '=', 1);
		$query->shouldReceive('update')->once()->with(['created_at' => 'foo', 'updated_at' => 'bar']);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until');
		$events->shouldReceive('fire');

		$model->id = 1;
		$model->syncOriginal();
		$model->created_at = 'foo';
		$model->updated_at = 'bar';
		$model->exists = true;
		$this->assertTrue($model->save());
	}


	public function testSaveIsCancelledIfSavingEventReturnsFalse()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes']);
		$query = m::mock(Builder::class);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until')->once()->with('eloquent.saving: '.get_class($model), $model)->andReturn(false);
		$model->exists = true;

		$this->assertFalse($model->save());
	}


	public function testUpdateIsCancelledIfUpdatingEventReturnsFalse()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes']);
		$query = m::mock(Builder::class);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until')->once()->with('eloquent.saving: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('until')->once()->with('eloquent.updating: '.get_class($model), $model)->andReturn(false);
		$model->exists = true;
		$model->foo = 'bar';

		$this->assertFalse($model->save());
	}


	public function testUpdateProcessWithoutTimestamps()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes', 'updateTimestamps', 'fireModelEvent']);
		$model->timestamps = false;
		$query = m::mock(Builder::class);
		$query->shouldReceive('where')->once()->with('id', '=', 1);
		$query->shouldReceive('update')->once()->with(['name' => 'taylor']);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->expects($this->never())->method('updateTimestamps');
		$model->expects($this->any())->method('fireModelEvent')->willReturn(true);

		$model->id = 1;
		$model->syncOriginal();
		$model->name = 'taylor';
		$model->exists = true;
		$this->assertTrue($model->save());
	}


	public function testUpdateUsesOldPrimaryKey()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes', 'updateTimestamps']);
		$query = m::mock(Builder::class);
		$query->shouldReceive('where')->once()->with('id', '=', 1);
		$query->shouldReceive('update')->once()->with(['id' => 2, 'foo' => 'bar']);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->expects($this->once())->method('updateTimestamps');
		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until')->once()->with('eloquent.saving: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('until')->once()->with('eloquent.updating: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('fire')->once()->with('eloquent.updated: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('fire')->once()->with('eloquent.saved: '.get_class($model), $model)->andReturn(true);

		$model->id = 1;
		$model->syncOriginal();
		$model->id = 2;
		$model->foo = 'bar';
		$model->exists = true;

		$this->assertTrue($model->save());
	}


	public function testTimestampsAreReturnedAsObjects()
	{
		$model = $this->getMock('EloquentDateModelStub', ['getDateFormat']);
		$model->expects($this->any())->method('getDateFormat')->willReturn('Y-m-d');
		$model->setRawAttributes([
			'created_at'	=> '2012-12-04',
			'updated_at'	=> '2012-12-05',
        ]);

		$this->assertInstanceOf(Carbon::class, $model->created_at);
		$this->assertInstanceOf(Carbon::class, $model->updated_at);
	}


	public function testTimestampsAreReturnedAsObjectsFromPlainDatesAndTimestamps()
	{
		$model = $this->getMock('EloquentDateModelStub', ['getDateFormat']);
		$model->expects($this->any())->method('getDateFormat')->willReturn('Y-m-d H:i:s');
		$model->setRawAttributes([
			'created_at'	=> '2012-12-04',
			'updated_at'	=> time(),
        ]);

		$this->assertInstanceOf(Carbon::class, $model->created_at);
		$this->assertInstanceOf(Carbon::class, $model->updated_at);
	}


	public function testTimestampsAreReturnedAsObjectsOnCreate()
	{
		$timestamps = [
			'created_at' => Carbon::now(),
			'updated_at' => Carbon::now()
        ];
		$model = new EloquentDateModelStub;
		Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver = m::mock(
            ConnectionResolverInterface::class
        ));
		$resolver->shouldReceive('connection')->andReturn($mockConnection = m::mock('StdClass'));
		$mockConnection->shouldReceive('getQueryGrammar')->andReturn($mockConnection);
		$mockConnection->shouldReceive('getDateFormat')->andReturn('Y-m-d H:i:s');
		$instance = $model->newInstance($timestamps);
		$this->assertInstanceOf(Carbon::class, $instance->updated_at);
		$this->assertInstanceOf(Carbon::class, $instance->created_at);
	}


	public function testDateTimeAttributesReturnNullIfSetToNull()
	{
		$timestamps = [
			'created_at' => Carbon::now(),
			'updated_at' => Carbon::now()
        ];
		$model = new EloquentDateModelStub;
		Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver = m::mock(
            ConnectionResolverInterface::class
        ));
		$resolver->shouldReceive('connection')->andReturn($mockConnection = m::mock('StdClass'));
		$mockConnection->shouldReceive('getQueryGrammar')->andReturn($mockConnection);
		$mockConnection->shouldReceive('getDateFormat')->andReturn('Y-m-d H:i:s');
		$instance = $model->newInstance($timestamps);

		$instance->created_at = null;
		$this->assertNull($instance->created_at);
	}


	public function testTimestampsAreCreatedFromStringsAndIntegers()
	{
		$model = new EloquentDateModelStub;
		$model->created_at = '2013-05-22 00:00:00';
		$this->assertInstanceOf(Carbon::class, $model->created_at);

		$model = new EloquentDateModelStub;
		$model->created_at = time();
		$this->assertInstanceOf(Carbon::class, $model->created_at);

		$model = new EloquentDateModelStub;
		$model->created_at = '2012-01-01';
		$this->assertInstanceOf(Carbon::class, $model->created_at);
	}


	public function testInsertProcess()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes', 'updateTimestamps']);
		$query = m::mock(Builder::class);
		$query->shouldReceive('insertGetId')->once()->with(['name' => 'taylor'], 'id')->andReturn(1);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->expects($this->once())->method('updateTimestamps');

		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until')->once()->with('eloquent.saving: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('until')->once()->with('eloquent.creating: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('fire')->once()->with('eloquent.created: '.get_class($model), $model);
		$events->shouldReceive('fire')->once()->with('eloquent.saved: '.get_class($model), $model);

		$model->name = 'taylor';
		$model->exists = false;
		$this->assertTrue($model->save());
		$this->assertEquals(1, $model->id);
		$this->assertTrue($model->exists);

		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes', 'updateTimestamps']);
		$query = m::mock(Builder::class);
		$query->shouldReceive('insert')->once()->with(['name' => 'taylor']);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->expects($this->once())->method('updateTimestamps');
		$model->setIncrementing(false);

		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until')->once()->with('eloquent.saving: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('until')->once()->with('eloquent.creating: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('fire')->once()->with('eloquent.created: '.get_class($model), $model);
		$events->shouldReceive('fire')->once()->with('eloquent.saved: '.get_class($model), $model);

		$model->name = 'taylor';
		$model->exists = false;
		$this->assertTrue($model->save());
		$this->assertNull($model->id);
		$this->assertTrue($model->exists);
	}


	public function testInsertIsCancelledIfCreatingEventReturnsFalse()
	{
		$model = $this->getMock('EloquentModelStub', ['newQueryWithoutScopes']);
		$query = m::mock(Builder::class);
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('until')->once()->with('eloquent.saving: '.get_class($model), $model)->andReturn(true);
		$events->shouldReceive('until')->once()->with('eloquent.creating: '.get_class($model), $model)->andReturn(false);

		$this->assertFalse($model->save());
		$this->assertFalse($model->exists);
	}


	public function testDeleteProperlyDeletesModel()
	{
		$model = $this->getMock(Model::class, ['newQueryWithoutScopes', 'updateTimestamps', 'touchOwners']);
		$query = m::mock('stdClass');
		$query->shouldReceive('where')->once()->with('id', 1)->andReturn($query);
		$query->shouldReceive('delete')->once();
		$model->expects($this->once())->method('newQueryWithoutScopes')->willReturn($query);
		$model->expects($this->once())->method('touchOwners');
		$model->exists = true;
		$model->id = 1;
		$model->delete();
	}


	public function testNewQueryReturnsEloquentQueryBuilder()
	{
		$conn = m::mock(Connection::class);
		$grammar = m::mock(Grammar::class);
		$processor = m::mock(Processor::class);
		$conn->shouldReceive('getQueryGrammar')->once()->andReturn($grammar);
		$conn->shouldReceive('getPostProcessor')->once()->andReturn($processor);
		EloquentModelStub::setConnectionResolver($resolver = m::mock(
            ConnectionResolverInterface::class
        ));
		$resolver->shouldReceive('connection')->andReturn($conn);
		$model = new EloquentModelStub;
		$builder = $model->newQuery();
		$this->assertInstanceOf(Builder::class, $builder);
	}


	public function testGetAndSetTableOperations()
	{
		$model = new EloquentModelStub;
		$this->assertEquals('stub', $model->getTable());
		$model->setTable('foo');
		$this->assertEquals('foo', $model->getTable());
	}


	public function testGetKeyReturnsValueOfPrimaryKey()
	{
		$model = new EloquentModelStub;
		$model->id = 1;
		$this->assertEquals(1, $model->getKey());
		$this->assertEquals('id', $model->getKeyName());
	}


	public function testConnectionManagement()
	{
		EloquentModelStub::setConnectionResolver($resolver = m::mock(
            ConnectionResolverInterface::class
        ));
		$model = new EloquentModelStub;
		$model->setConnection('foo');
		$resolver->shouldReceive('connection')->once()->with('foo')->andReturn('bar');

		$this->assertEquals('bar', $model->getConnection());
	}


	public function testToArray()
	{
		$model = new EloquentModelStub;
		$model->name = 'foo';
		$model->age = null;
		$model->password = 'password1';
		$model->setHidden(['password']);
		$model->setRelation('names', new Illuminate\Database\Eloquent\Collection([
			new EloquentModelStub(['bar' => 'baz']), new EloquentModelStub(['bam' => 'boom'])
        ]));
		$model->setRelation('partner', new EloquentModelStub(['name' => 'abby']));
		$model->setRelation('group', null);
		$model->setRelation('multi', new Illuminate\Database\Eloquent\Collection);
		$array = $model->toArray();

		$this->assertIsArray($array);
		$this->assertEquals('foo', $array['name']);
		$this->assertEquals('baz', $array['names'][0]['bar']);
		$this->assertEquals('boom', $array['names'][1]['bam']);
		$this->assertEquals('abby', $array['partner']['name']);
		$this->assertNull($array['group']);
		$this->assertEquals([], $array['multi']);
		$this->assertFalse(isset($array['password']));

		$model->setAppends(['appendable']);
		$array = $model->toArray();
		$this->assertEquals('appended', $array['appendable']);
	}


	public function testToArrayIncludesDefaultFormattedTimestamps()
	{
		$model = new EloquentDateModelStub;
		$model->setRawAttributes([
			'created_at'	=> '2012-12-04',
			'updated_at'	=> '2012-12-05',
        ]);

		$array = $model->toArray();

		$this->assertEquals('2012-12-04 00:00:00', $array['created_at']);
		$this->assertEquals('2012-12-05 00:00:00', $array['updated_at']);
	}


	public function testToArrayIncludesCustomFormattedTimestamps()
	{
		$model = new EloquentDateModelStub;
		$model->setRawAttributes([
			'created_at'	=> '2012-12-04',
			'updated_at'	=> '2012-12-05',
        ]);

		$array = $model->toArray();

		$this->assertEquals('2012-12-04 00:00:00', $array['created_at']);
		$this->assertEquals('2012-12-05 00:00:00', $array['updated_at']);
	}


	public function testVisibleCreatesArrayWhitelist()
	{
		$model = new EloquentModelStub;
		$model->setVisible(['name']);
		$model->name = 'Taylor';
		$model->age = 26;
		$array = $model->toArray();

		$this->assertEquals(['name' => 'Taylor'], $array);
	}


	public function testHiddenCanAlsoExcludeRelationships()
	{
		$model = new EloquentModelStub;
		$model->name = 'Taylor';
		$model->setRelation('foo', ['bar']);
		$model->setHidden(['foo', 'list_items', 'password']);
		$array = $model->toArray();

		$this->assertEquals(['name' => 'Taylor'], $array);
	}


	public function testToArraySnakeAttributes()
	{
		$model = new EloquentModelStub;
		$model->setRelation('namesList', new Illuminate\Database\Eloquent\Collection([
			new EloquentModelStub(['bar' => 'baz']), new EloquentModelStub(['bam' => 'boom'])
        ]));
		$array = $model->toArray();

		$this->assertEquals('baz', $array['names_list'][0]['bar']);
		$this->assertEquals('boom', $array['names_list'][1]['bam']);

		$model = new EloquentModelCamelStub;
		$model->setRelation('namesList', new Illuminate\Database\Eloquent\Collection([
			new EloquentModelStub(['bar' => 'baz']), new EloquentModelStub(['bam' => 'boom'])
        ]));
		$array = $model->toArray();

		$this->assertEquals('baz', $array['namesList'][0]['bar']);
		$this->assertEquals('boom', $array['namesList'][1]['bam']);
	}


	public function testToArrayUsesMutators()
	{
		$model = new EloquentModelStub;
		$model->list_items = [1, 2, 3];
		$array = $model->toArray();

		$this->assertEquals([1, 2, 3], $array['list_items']);
	}


	public function testFillable()
	{
		$model = new EloquentModelStub;
		$model->fillable(['name', 'age']);
		$model->fill(['name' => 'foo', 'age' => 'bar']);
		$this->assertEquals('foo', $model->name);
		$this->assertEquals('bar', $model->age);
	}


	public function testUnguardAllowsAnythingToBeSet()
	{
		$model = new EloquentModelStub;
		EloquentModelStub::unguard();
		$model->guard(['*']);
		$model->fill(['name' => 'foo', 'age' => 'bar']);
		$this->assertEquals('foo', $model->name);
		$this->assertEquals('bar', $model->age);
		EloquentModelStub::setUnguardState(false);
	}


	public function testUnderscorePropertiesAreNotFilled()
	{
		$model = new EloquentModelStub;
		$model->fill(['_method' => 'PUT']);
		$this->assertEquals([], $model->getAttributes());
	}


	public function testGuarded()
	{
		$model = new EloquentModelStub;
		$model->guard(['name', 'age']);
		$model->fill(['name' => 'foo', 'age' => 'bar', 'foo' => 'bar']);
		$this->assertFalse(isset($model->name));
		$this->assertFalse(isset($model->age));
		$this->assertEquals('bar', $model->foo);
	}


	public function testFillableOverridesGuarded()
	{
		$model = new EloquentModelStub;
		$model->guard(['name', 'age']);
		$model->fillable(['age', 'foo']);
		$model->fill(['name' => 'foo', 'age' => 'bar', 'foo' => 'bar']);
		$this->assertFalse(isset($model->name));
		$this->assertEquals('bar', $model->age);
		$this->assertEquals('bar', $model->foo);
	}


    public function testGlobalGuarded()
    {
        $this->expectException(Illuminate\Database\Eloquent\MassAssignmentException::class);
        $model = new EloquentModelStub;
        $model->guard(['*']);
        $model->fill(['name' => 'foo', 'age' => 'bar', 'votes' => 'baz']);
    }


	public function testHasOneCreatesProperRelation()
	{
		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->hasOne('EloquentModelSaveStub');
		$this->assertEquals('save_stub.eloquent_model_stub_id', $relation->getForeignKey());

		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->hasOne('EloquentModelSaveStub', 'foo');
		$this->assertEquals('save_stub.foo', $relation->getForeignKey());
		$this->assertSame($model, $relation->getParent());
		$this->assertInstanceOf('EloquentModelSaveStub', $relation->getQuery()->getModel());
	}


	public function testMorphOneCreatesProperRelation()
	{
		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->morphOne('EloquentModelSaveStub', 'morph');
		$this->assertEquals('save_stub.morph_id', $relation->getForeignKey());
		$this->assertEquals('save_stub.morph_type', $relation->getMorphType());
		$this->assertEquals('EloquentModelStub', $relation->getMorphClass());
	}


	public function testHasManyCreatesProperRelation()
	{
		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->hasMany('EloquentModelSaveStub');
		$this->assertEquals('save_stub.eloquent_model_stub_id', $relation->getForeignKey());

		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->hasMany('EloquentModelSaveStub', 'foo');
		$this->assertEquals('save_stub.foo', $relation->getForeignKey());
		$this->assertSame($model, $relation->getParent());
		$this->assertInstanceOf('EloquentModelSaveStub', $relation->getQuery()->getModel());
	}


	public function testMorphManyCreatesProperRelation()
	{
		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->morphMany('EloquentModelSaveStub', 'morph');
		$this->assertEquals('save_stub.morph_id', $relation->getForeignKey());
		$this->assertEquals('save_stub.morph_type', $relation->getMorphType());
		$this->assertEquals('EloquentModelStub', $relation->getMorphClass());
	}


	public function testBelongsToCreatesProperRelation()
	{
		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->belongsToStub();
		$this->assertEquals('belongs_to_stub_id', $relation->getForeignKey());
		$this->assertSame($model, $relation->getParent());
		$this->assertInstanceOf('EloquentModelSaveStub', $relation->getQuery()->getModel());

		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->belongsToExplicitKeyStub();
		$this->assertEquals('foo', $relation->getForeignKey());
	}


	public function testMorphToCreatesProperRelation()
	{
		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->morphToStub();
		$this->assertEquals('morph_to_stub_id', $relation->getForeignKey());
		$this->assertSame($model, $relation->getParent());
		$this->assertInstanceOf('EloquentModelSaveStub', $relation->getQuery()->getModel());
	}


	public function testBelongsToManyCreatesProperRelation()
	{
		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->belongsToMany('EloquentModelSaveStub');
		$this->assertEquals('eloquent_model_save_stub_eloquent_model_stub.eloquent_model_stub_id', $relation->getForeignKey());
		$this->assertEquals('eloquent_model_save_stub_eloquent_model_stub.eloquent_model_save_stub_id', $relation->getOtherKey());
		$this->assertSame($model, $relation->getParent());
		$this->assertInstanceOf('EloquentModelSaveStub', $relation->getQuery()->getModel());
		$this->assertEquals(__FUNCTION__, $relation->getRelationName());

		$model = new EloquentModelStub;
		$this->addMockConnection($model);
		$relation = $model->belongsToMany('EloquentModelSaveStub', 'table', 'foreign', 'other');
		$this->assertEquals('table.foreign', $relation->getForeignKey());
		$this->assertEquals('table.other', $relation->getOtherKey());
		$this->assertSame($model, $relation->getParent());
		$this->assertInstanceOf('EloquentModelSaveStub', $relation->getQuery()->getModel());
	}


	public function testModelsAssumeTheirName()
	{
		$model = new EloquentModelWithoutTableStub;
		$this->assertEquals('eloquent_model_without_table_stubs', $model->getTable());

		require_once __DIR__.'/stubs/EloquentModelNamespacedStub.php';
		$namespacedModel = new Foo\Bar\EloquentModelNamespacedStub;
		$this->assertEquals('eloquent_model_namespaced_stubs', $namespacedModel->getTable());
	}


	public function testTheMutatorCacheIsPopulated()
	{
		$class = new EloquentModelStub;

		$expectedAttributes = [
			'list_items',
			'password',
			'appendable'
		];

		$this->assertEquals($expectedAttributes, $class->getMutatedAttributes());
	}


	public function testCloneModelMakesAFreshCopyOfTheModel()
	{
		$class = new EloquentModelStub;
		$class->id = 1;
		$class->exists = true;
		$class->first = 'taylor';
		$class->last = 'otwell';
		$class->created_at = $class->freshTimestamp();
		$class->updated_at = $class->freshTimestamp();
		$class->setRelation('foo', ['bar']);

		$clone = $class->replicate();

		$this->assertNull($clone->id);
		$this->assertFalse($clone->exists);
		$this->assertEquals('taylor', $clone->first);
		$this->assertEquals('otwell', $clone->last);
		$this->assertObjectNotHasAttribute('created_at', $clone);
		$this->assertObjectNotHasAttribute('updated_at', $clone);
		$this->assertEquals(['bar'], $clone->foo);
	}


	public function testModelObserversCanBeAttachedToModels()
	{
		EloquentModelStub::setEventDispatcher($events = m::mock(Dispatcher::class));
		$events->shouldReceive('listen')->once()->with('eloquent.creating: EloquentModelStub', 'EloquentTestObserverStub@creating');
		$events->shouldReceive('listen')->once()->with('eloquent.saved: EloquentModelStub', 'EloquentTestObserverStub@saved');
		$events->shouldReceive('forget');
		EloquentModelStub::observe(new EloquentTestObserverStub);
		EloquentModelStub::flushEventListeners();
	}


	public function testSetObservableEvents()
	{
		$class = new EloquentModelStub;
		$class->setObservableEvents(['foo']);

		$this->assertContains('foo', $class->getObservableEvents());
	}


	public function testAddObservableEvent()
	{
		$class = new EloquentModelStub;
		$class->addObservableEvents('foo');

		$this->assertContains('foo', $class->getObservableEvents());
	}

	public function testAddMultipleObserveableEvents()
	{
		$class = new EloquentModelStub;
		$class->addObservableEvents('foo', 'bar');

		$this->assertContains('foo', $class->getObservableEvents());
		$this->assertContains('bar', $class->getObservableEvents());
	}


	public function testRemoveObservableEvent()
	{
		$class = new EloquentModelStub;
		$class->setObservableEvents(['foo', 'bar']);
		$class->removeObservableEvents('bar');

		$this->assertNotContains('bar', $class->getObservableEvents());
	}

	public function testRemoveMultipleObservableEvents()
	{
		$class = new EloquentModelStub;
		$class->setObservableEvents(['foo', 'bar']);
		$class->removeObservableEvents('foo', 'bar');

		$this->assertNotContains('foo', $class->getObservableEvents());
		$this->assertNotContains('bar', $class->getObservableEvents());
	}


    public function testGetModelAttributeMethodThrowsExceptionIfNotRelation()
    {
        $this->expectException(LogicException::class);
        $model = new EloquentModelStub;
        $relation = $model->incorrect_relation_stub;
    }


	public function testModelIsBootedOnUnserialize()
	{
		$model = new EloquentModelBootingTestStub;
		$this->assertTrue(EloquentModelBootingTestStub::isBooted());
		$model->foo = 'bar';
		$string = serialize($model);
		$model = null;
		EloquentModelBootingTestStub::unboot();
		$this->assertFalse(EloquentModelBootingTestStub::isBooted());
		$model = unserialize($string);
		$this->assertTrue(EloquentModelBootingTestStub::isBooted());
	}


	public function testAppendingOfAttributes()
	{
		$model = new EloquentModelAppendsStub;

		$this->assertTrue(isset($model->is_admin));
		$this->assertTrue(isset($model->camelCased));
		$this->assertTrue(isset($model->StudlyCased));

		$this->assertEquals('admin', $model->is_admin);
		$this->assertEquals('camelCased', $model->camelCased);
		$this->assertEquals('StudlyCased', $model->StudlyCased);

		$model->setHidden(['is_admin', 'camelCased', 'StudlyCased']);
		$this->assertEquals([], $model->toArray());

		$model->setVisible([]);
		$this->assertEquals([], $model->toArray());
	}


	public function testReplicateCreatesANewModelInstanceWithSameAttributeValues()
	{
		$model = new EloquentModelStub;
		$model->id = 'id';
		$model->foo = 'bar';
		$model->created_at = new DateTime;
		$model->updated_at = new DateTime;
		$replicated = $model->replicate();

		$this->assertNull($replicated->id);
		$this->assertEquals('bar', $replicated->foo);
		$this->assertNull($replicated->created_at);
		$this->assertNull($replicated->updated_at);
	}


	public function testIncrementOnExistingModelCallsQueryAndSetsAttribute()
	{
		$model = m::mock('EloquentModelStub[newQuery]');
		$model->exists = true;
		$model->id = 1;
		$model->syncOriginalAttribute('id');
		$model->foo = 2;

		$model->shouldReceive('newQuery')->andReturn($query = m::mock('StdClass'));
		$query->shouldReceive('where')->andReturn($query);
		$query->shouldReceive('increment');

		$model->publicIncrement('foo');

		$this->assertEquals(3, $model->foo);
		$this->assertFalse($model->isDirty());
	}

	public function testRelationshipTouchOwnersIsPropagated()
	{
		$relation = $this->getMockBuilder(BelongsTo::class)->onlyMethods(['touch'])->disableOriginalConstructor()->getMock();
		$relation->expects($this->once())->method('touch');

		$model = m::mock('EloquentModelStub[partner]');
		$this->addMockConnection($model);
		$model->shouldReceive('partner')->once()->andReturn($relation);
		$model->setTouchedRelations(['partner']);

		$mockPartnerModel = m::mock('EloquentModelStub[touchOwners]');
		$mockPartnerModel->shouldReceive('touchOwners')->once();
		$model->setRelation('partner', $mockPartnerModel);

		$model->touchOwners();
	}


	public function testRelationshipTouchOwnersIsNotPropagatedIfNoRelationshipResult()
	{
		$relation = $this->getMockBuilder(BelongsTo::class)->onlyMethods(['touch'])->disableOriginalConstructor()->getMock();
		$relation->expects($this->once())->method('touch');

		$model = m::mock('EloquentModelStub[partner]');
		$this->addMockConnection($model);
		$model->shouldReceive('partner')->once()->andReturn($relation);
		$model->setTouchedRelations(['partner']);

		$model->setRelation('partner', null);

		$model->touchOwners();
	}


	public function testTimestampsAreNotUpdatedWithTimestampsFalseSaveOption()
	{
		$model = m::mock('EloquentModelStub[newQueryWithoutScopes]');
		$query = m::mock(Builder::class);
		$query->shouldReceive('where')->once()->with('id', '=', 1);
		$query->shouldReceive('update')->once()->with(['name' => 'taylor']);
		$model->shouldReceive('newQueryWithoutScopes')->once()->andReturn($query);

		$model->id = 1;
		$model->syncOriginal();
		$model->name = 'taylor';
		$model->exists = true;
		$this->assertTrue($model->save(['timestamps' => false]));
		$this->assertNull($model->updated_at);
	}


	protected function addMockConnection($model)
	{
		$model->setConnectionResolver($resolver = m::mock(ConnectionResolverInterface::class));
		$resolver->shouldReceive('connection')->andReturn(m::mock(Connection::class));
		$model->getConnection()->shouldReceive('getQueryGrammar')->andReturn(m::mock(
            Grammar::class
        ));
		$model->getConnection()->shouldReceive('getPostProcessor')->andReturn(m::mock(
            Processor::class
        ));
	}

}

class EloquentTestObserverStub {
	public function creating() {}
	public function saved() {}
}

class EloquentModelStub extends Illuminate\Database\Eloquent\Model {
	protected $table = 'stub';
	protected $guarded = [];
	protected $morph_to_stub_type = 'EloquentModelSaveStub';
	public function getListItemsAttribute($value)
	{
		return json_decode($value, true);
	}
	public function setListItemsAttribute($value)
	{
		$this->attributes['list_items'] = json_encode($value);
	}
	public function getPasswordAttribute()
	{
		return '******';
	}
	public function setPasswordAttribute($value)
	{
		$this->attributes['password_hash'] = md5($value);
	}
	public function publicIncrement($column, $amount = 1)
	{
		return $this->increment($column, $amount);
	}
	public function belongsToStub()
	{
		return $this->belongsTo('EloquentModelSaveStub');
	}
	public function morphToStub()
	{
		return $this->morphTo();
	}
	public function belongsToExplicitKeyStub()
	{
		return $this->belongsTo('EloquentModelSaveStub', 'foo');
	}
	public function incorrectRelationStub()
	{
		return 'foo';
	}
	public function getDates()
	{
		return [];
	}
	public function getAppendableAttribute()
	{
		return 'appended';
	}
}

class EloquentModelCamelStub extends EloquentModelStub {
	public static $snakeAttributes = false;
}

class EloquentDateModelStub extends EloquentModelStub {
	public function getDates()
	{
		return ['created_at', 'updated_at'];
	}
}

class EloquentModelSaveStub extends Illuminate\Database\Eloquent\Model {
	protected $table = 'save_stub';
	protected $guarded = [];
	public function save(array $options = []) { $_SERVER['__eloquent.saved'] = true; }
	public function setIncrementing($value)
	{
		$this->incrementing = $value;
	}
}

class EloquentModelFindStub extends Illuminate\Database\Eloquent\Model {
	public function newQuery()
	{
		$mock = m::mock(Builder::class);
		$mock->shouldReceive('find')->once()->with(1, ['*'])->andReturn('foo');
		return $mock;
	}
}

class EloquentModelFindWithWritePdoStub extends Illuminate\Database\Eloquent\Model {
	public function newQuery()
	{
		$mock = m::mock(Builder::class);
		$mock->shouldReceive('useWritePdo')->once()->andReturnSelf();
		$mock->shouldReceive('find')->once()->with(1)->andReturn('foo');

		return $mock;
	}
}

class EloquentModelFindNotFoundStub extends Illuminate\Database\Eloquent\Model {
	public function newQuery()
	{
		$mock = m::mock(Builder::class);
		$mock->shouldReceive('find')->once()->with(1, ['*'])->andReturn(null);
		return $mock;
	}
}

class EloquentModelDestroyStub extends Illuminate\Database\Eloquent\Model {
	public function newQuery()
	{
		$mock = m::mock(Builder::class);
		$mock->shouldReceive('whereIn')->once()->with('id', [1, 2, 3])->andReturn($mock);
		$mock->shouldReceive('get')->once()->andReturn([$model = m::mock('StdClass')]);
		$model->shouldReceive('delete')->once();
		return $mock;
	}
}

class EloquentModelHydrateRawStub extends Illuminate\Database\Eloquent\Model {
	public static function hydrate(array $items, $connection = null) { return 'hydrated'; }
	public function getConnection()
	{
		$mock = m::mock(Connection::class);
		$mock->shouldReceive('select')->once()->with('SELECT ?', ['foo'])->andReturn([]);
		return $mock;
	}
}

class EloquentModelFindManyStub extends Illuminate\Database\Eloquent\Model {
	public function newQuery()
	{
		$mock = m::mock(Builder::class);
		$mock->shouldReceive('find')->once()->with([1, 2], ['*'])->andReturn('foo');
		return $mock;
	}
}

class EloquentModelWithStub extends Illuminate\Database\Eloquent\Model {
	public function newQuery()
	{
		$mock = m::mock(Builder::class);
		$mock->shouldReceive('with')->once()->with(['foo', 'bar'])->andReturn('foo');
		return $mock;
	}
}

class EloquentModelWithoutTableStub extends Illuminate\Database\Eloquent\Model {}

class EloquentModelBootingTestStub extends Illuminate\Database\Eloquent\Model {
	public static function unboot()
	{
		unset(static::$booted[static::class]);
	}
	public static function isBooted()
	{
		return array_key_exists(static::class, static::$booted);
	}
}

class EloquentModelAppendsStub extends Illuminate\Database\Eloquent\Model {
	protected $appends = ['is_admin', 'camelCased', 'StudlyCased'];
	public function getIsAdminAttribute()
	{
		return 'admin';
	}
	public function getCamelCasedAttribute()
	{
		return 'camelCased';
	}
	public function getStudlyCasedAttribute()
	{
		return 'StudlyCased';
	}
}
