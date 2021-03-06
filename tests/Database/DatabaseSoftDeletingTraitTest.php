<?php

use Carbon\Carbon;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class DatabaseSoftDeletingTraitTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testDeleteSetsSoftDeletedColumn()
    {
        $model = m::mock('DatabaseSoftDeletingTraitStub');
        $model->makePartial();
        $model->shouldReceive('newQuery')->andReturn($query = m::mock('StdClass'));
		$query->shouldReceive('where')->once()->with('id', 1)->andReturn($query);
		$query->shouldReceive('update')->once()->with(['deleted_at' => 'date-time']);
		$model->delete();

		$this->assertInstanceOf(Carbon::class, $model->deleted_at);
	}


	public function testRestore()
	{
		$model = m::mock('DatabaseSoftDeletingTraitStub');
		$model->makePartial();
		$model->shouldReceive('fireModelEvent')->with('restoring')->andReturn(true);
		$model->shouldReceive('save')->once();
		$model->shouldReceive('fireModelEvent')->with('restored', false)->andReturn(true);

		$model->restore();

		$this->assertNull($model->deleted_at);
	}


	public function testRestoreCancel()
	{
		$model = m::mock('DatabaseSoftDeletingTraitStub');
		$model->makePartial();
		$model->shouldReceive('fireModelEvent')->with('restoring')->andReturn(false);
		$model->shouldReceive('save')->never();

		$this->assertFalse($model->restore());
	}

}


class DatabaseSoftDeletingTraitStub {
	use Illuminate\Database\Eloquent\SoftDeletingTrait;
	public $deleted_at;
	public function newQuery()
	{
		//
	}
	public function getKey()
	{
		return 1;
	}
	public function getKeyName()
	{
		return 'id';
	}
	public function save()
	{
		//
	}
	public function delete()
	{
		return $this->performDeleteOnModel();
	}
	public function fireModelEvent()
	{
		//
	}
	public function freshTimestamp()
	{
		return Carbon::now();
	}
	public function fromDateTime()
	{
		return 'date-time';
	}
}
