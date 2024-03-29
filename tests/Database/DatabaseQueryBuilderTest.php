<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression as Raw;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Pagination\Factory;
use Illuminate\Support\Collection;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;
use Mockery\MockInterface;

class DatabaseQueryBuilderTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testBasicSelect(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users');
        $this->assertEquals('select * from "users"', $builder->toSql());
	}


	public function testBasicSelectUseWritePdo(): void
    {
		$builder = $this->getMySqlBuilderWithProcessor();
		$builder->getConnection()->shouldReceive('select')->once()
			->with('select * from `users`', [], false);
		$builder->useWritePdo()->select('*')->from('users')->get();

		$builder = $this->getMySqlBuilderWithProcessor();
		$builder->getConnection()->shouldReceive('select')->once()
			->with('select * from `users`', []);
		$builder->select('*')->from('users')->get();
	}


	public function testBasicTableWrappingProtectsQuotationMarks(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('some"table');
		$this->assertEquals('select * from "some""table"', $builder->toSql());
	}

	public function testAliasWrappingAsWholeConstant(): void
    {
		$builder = $this->getBuilder();
		$builder->select('x.y as foo.bar')->from('baz');
		$this->assertEquals('select "x"."y" as "foo.bar" from "baz"', $builder->toSql());
	}

	public function testAddingSelects(): void
    {
		$builder = $this->getBuilder();
		$builder->select('foo')->addSelect('bar')->addSelect(['baz', 'boom'])->from('users');
		$this->assertEquals('select "foo", "bar", "baz", "boom" from "users"', $builder->toSql());
	}


	public function testBasicSelectWithPrefix(): void
    {
		$builder = $this->getBuilder();
		$builder->getGrammar()->setTablePrefix('prefix_');
		$builder->select('*')->from('users');
		$this->assertEquals('select * from "prefix_users"', $builder->toSql());
	}


	public function testBasicSelectDistinct(): void
    {
		$builder = $this->getBuilder();
		$builder->distinct()->select('foo', 'bar')->from('users');
		$this->assertEquals('select distinct "foo", "bar" from "users"', $builder->toSql());
	}


	public function testSelectWithCaching(): void
    {
		$cache = m::mock('stdClass');
		$driver = m::mock('stdClass');
		$query = $this->setupCacheTestQuery($cache, $driver);

		$query = $query->remember(5);

		$driver->shouldReceive('remember')
						 ->once()
						 ->with($query->getCacheKey(), 5, m::type('Closure'))
						 ->andReturnUsing(function($key, $minutes, $callback) { return $callback(); });


		$this->assertEquals($query->get(), ['results']);
	}


	public function testSelectWithCachingForever(): void
    {
		$cache = m::mock('stdClass');
		$driver = m::mock('stdClass');
		$query = $this->setupCacheTestQuery($cache, $driver);

		$query = $query->rememberForever();

		$driver->shouldReceive('rememberForever')
												->once()
												->with($query->getCacheKey(), m::type('Closure'))
												->andReturnUsing(function($key, $callback) { return $callback(); });



		$this->assertEquals($query->get(), ['results']);
	}


	public function testSelectWithCachingAndTags(): void
    {
		$taggedCache = m::mock('StdClass');
		$cache = m::mock('stdClass');
		$driver = m::mock('stdClass');

		$driver->shouldReceive('tags')
				->once()
				->with(['foo','bar'])
				->andReturn($taggedCache);

		$query = $this->setupCacheTestQuery($cache, $driver);
		$query = $query->cacheTags(['foo', 'bar'])->remember(5);

		$taggedCache->shouldReceive('remember')
						->once()
						->with($query->getCacheKey(), 5, m::type('Closure'))
						->andReturnUsing(function($key, $minutes, $callback) { return $callback(); });

		$this->assertEquals($query->get(), ['results']);
	}


	public function testBasicAlias(): void
    {
		$builder = $this->getBuilder();
		$builder->select('foo as bar')->from('users');
		$this->assertEquals('select "foo" as "bar" from "users"', $builder->toSql());
	}


	public function testBasicTableWrapping(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('public.users');
		$this->assertEquals('select * from "public"."users"', $builder->toSql());
	}


	public function testBasicWheres(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$this->assertEquals('select * from "users" where "id" = ?', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testMySqlWrappingProtectsQuotationMarks(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->From('some`table');
		$this->assertEquals('select * from `some``table`', $builder->toSql());
	}


	public function testWhereDayMySql(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('users')->whereDay('created_at', '=', 1);
		$this->assertEquals('select * from `users` where day(`created_at`) = ?', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testWhereMonthMySql(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
		$this->assertEquals('select * from `users` where month(`created_at`) = ?', $builder->toSql());
		$this->assertEquals([0 => 5], $builder->getBindings());
	}


	public function testWhereYearMySql(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
		$this->assertEquals('select * from `users` where year(`created_at`) = ?', $builder->toSql());
		$this->assertEquals([0 => 2014], $builder->getBindings());
	}


	public function testWhereDayPostgres(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('users')->whereDay('created_at', '=', 1);
		$this->assertEquals('select * from "users" where day("created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testWhereMonthPostgres(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
		$this->assertEquals('select * from "users" where month("created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 5], $builder->getBindings());
	}


	public function testWhereYearPostgres(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
		$this->assertEquals('select * from "users" where year("created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 2014], $builder->getBindings());
	}


	public function testWhereDaySqlite(): void
    {
		$builder = $this->getSQLiteBuilder();
		$builder->select('*')->from('users')->whereDay('created_at', '=', 1);
		$this->assertEquals('select * from "users" where strftime(\'%d\', "created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testWhereMonthSqlite(): void
    {
		$builder = $this->getSQLiteBuilder();
		$builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
		$this->assertEquals('select * from "users" where strftime(\'%m\', "created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 5], $builder->getBindings());
	}


	public function testWhereYearSqlite(): void
    {
		$builder = $this->getSQLiteBuilder();
		$builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
		$this->assertEquals('select * from "users" where strftime(\'%Y\', "created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 2014], $builder->getBindings());
	}


	public function testWhereDaySqlServer(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('users')->whereDay('created_at', '=', 1);
		$this->assertEquals('select * from "users" where day("created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testWhereMonthSqlServer(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
		$this->assertEquals('select * from "users" where month("created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 5], $builder->getBindings());
	}


	public function testWhereYearSqlServer(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
		$this->assertEquals('select * from "users" where year("created_at") = ?', $builder->toSql());
		$this->assertEquals([0 => 2014], $builder->getBindings());
	}


	public function testWhereBetweens(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereBetween('id', [1, 2]);
		$this->assertEquals('select * from "users" where "id" between ? and ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereNotBetween('id', [1, 2]);
		$this->assertEquals('select * from "users" where "id" not between ? and ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
	}


	public function testBasicOrWheres(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1)->orWhere('email', '=', 'foo');
		$this->assertEquals('select * from "users" where "id" = ? or "email" = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
	}


	public function testRawWheres(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereRaw('id = ? or email = ?', [1, 'foo']);
		$this->assertEquals('select * from "users" where id = ? or email = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
	}


	public function testRawOrWheres(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1)->orWhereRaw('email = ?', ['foo']);
		$this->assertEquals('select * from "users" where "id" = ? or email = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
	}


	public function testBasicWhereIns(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereIn('id', [1, 2, 3]);
		$this->assertEquals('select * from "users" where "id" in (?, ?, ?)', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1)->orWhereIn('id', [1, 2, 3]);
		$this->assertEquals('select * from "users" where "id" = ? or "id" in (?, ?, ?)', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 1, 2 => 2, 3 => 3], $builder->getBindings());
	}


	public function testBasicWhereNotIns(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereNotIn('id', [1, 2, 3]);
		$this->assertEquals('select * from "users" where "id" not in (?, ?, ?)', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1)->orWhereNotIn('id', [1, 2, 3]);
		$this->assertEquals('select * from "users" where "id" = ? or "id" not in (?, ?, ?)', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 1, 2 => 2, 3 => 3], $builder->getBindings());
	}


	public function testEmptyWhereIns(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereIn('id', []);
		$this->assertEquals('select * from "users" where 0 = 1', $builder->toSql());
		$this->assertEquals([], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1)->orWhereIn('id', []);
		$this->assertEquals('select * from "users" where "id" = ? or 0 = 1', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testEmptyWhereNotIns(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereNotIn('id', []);
		$this->assertEquals('select * from "users" where 1 = 1', $builder->toSql());
		$this->assertEquals([], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1)->orWhereNotIn('id', []);
		$this->assertEquals('select * from "users" where "id" = ? or 1 = 1', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testUnions(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$builder->union($this->getBuilder()->select('*')->from('users')->where('id', '=', 2));
		$this->assertEquals('select * from "users" where "id" = ? union select * from "users" where "id" = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$builder->union($this->getMySqlBuilder()->select('*')->from('users')->where('id', '=', 2));
		$this->assertEquals('(select * from `users` where `id` = ?) union (select * from `users` where `id` = ?)', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
	}


	public function testUnionAlls(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$builder->unionAll($this->getBuilder()->select('*')->from('users')->where('id', '=', 2));
		$this->assertEquals('select * from "users" where "id" = ? union all select * from "users" where "id" = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
	}


	public function testMultipleUnions(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$builder->union($this->getBuilder()->select('*')->from('users')->where('id', '=', 2));
		$builder->union($this->getBuilder()->select('*')->from('users')->where('id', '=', 3));
		$this->assertEquals('select * from "users" where "id" = ? union select * from "users" where "id" = ? union select * from "users" where "id" = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
	}


	public function testMultipleUnionAlls(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$builder->unionAll($this->getBuilder()->select('*')->from('users')->where('id', '=', 2));
		$builder->unionAll($this->getBuilder()->select('*')->from('users')->where('id', '=', 3));
		$this->assertEquals('select * from "users" where "id" = ? union all select * from "users" where "id" = ? union all select * from "users" where "id" = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
	}


	public function testUnionOrderBys(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$builder->union($this->getBuilder()->select('*')->from('users')->where('id', '=', 2));
		$builder->orderBy('id', 'desc');
		$this->assertEquals('select * from "users" where "id" = ? union select * from "users" where "id" = ? order by "id" desc', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
	}


	public function testUnionLimitsAndOffsets(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users');
		$builder->union($this->getBuilder()->select('*')->from('dogs'));
		$builder->skip(5)->take(10);
		$this->assertEquals('select * from "users" union select * from "dogs" limit 10 offset 5', $builder->toSql());
	}


	public function testMySqlUnionOrderBys(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1);
		$builder->union($this->getMySqlBuilder()->select('*')->from('users')->where('id', '=', 2));
		$builder->orderBy('id', 'desc');
		$this->assertEquals('(select * from `users` where `id` = ?) union (select * from `users` where `id` = ?) order by `id` desc', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
	}


	public function testMySqlUnionLimitsAndOffsets(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('users');
		$builder->union($this->getMySqlBuilder()->select('*')->from('dogs'));
		$builder->skip(5)->take(10);
		$this->assertEquals('(select * from `users`) union (select * from `dogs`) limit 10 offset 5', $builder->toSql());
	}


	public function testSubSelectWhereIns(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereIn('id', function($q)
		{
			$q->select('id')->from('users')->where('age', '>', 25)->take(3);
		});
		$this->assertEquals('select * from "users" where "id" in (select "id" from "users" where "age" > ? limit 3)', $builder->toSql());
		$this->assertEquals([25], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereNotIn('id', function($q)
		{
			$q->select('id')->from('users')->where('age', '>', 25)->take(3);
		});
		$this->assertEquals('select * from "users" where "id" not in (select "id" from "users" where "age" > ? limit 3)', $builder->toSql());
		$this->assertEquals([25], $builder->getBindings());
	}


	public function testBasicWhereNulls(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereNull('id');
		$this->assertEquals('select * from "users" where "id" is null', $builder->toSql());
		$this->assertEquals([], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '=', 1)->orWhereNull('id');
		$this->assertEquals('select * from "users" where "id" = ? or "id" is null', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testBasicWhereNotNulls(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->whereNotNull('id');
		$this->assertEquals('select * from "users" where "id" is not null', $builder->toSql());
		$this->assertEquals([], $builder->getBindings());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', '>', 1)->orWhereNotNull('id');
		$this->assertEquals('select * from "users" where "id" > ? or "id" is not null', $builder->toSql());
		$this->assertEquals([0 => 1], $builder->getBindings());
	}


	public function testGroupBys(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->groupBy('id', 'email');
		$this->assertEquals('select * from "users" group by "id", "email"', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->groupBy(['id', 'email']);
		$this->assertEquals('select * from "users" group by "id", "email"', $builder->toSql());
	}


	public function testOrderBys(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->orderBy('email')->orderBy('age', 'desc');
		$this->assertEquals('select * from "users" order by "email" asc, "age" desc', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->orderBy('email')->orderByRaw('"age" ? desc', ['foo']);
		$this->assertEquals('select * from "users" order by "email" asc, "age" ? desc', $builder->toSql());
		$this->assertEquals(['foo'], $builder->getBindings());
	}


	public function testHavings(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->having('email', '>', 1);
		$this->assertEquals('select * from "users" having "email" > ?', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')
			->orHaving('email', '=', 'test@example.com')
			->orHaving('email', '=', 'test2@example.com');
		$this->assertEquals('select * from "users" having "email" = ? or "email" = ?', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->groupBy('email')->having('email', '>', 1);
		$this->assertEquals('select * from "users" group by "email" having "email" > ?', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('email as foo_email')->from('users')->having('foo_email', '>', 1);
		$this->assertEquals('select "email" as "foo_email" from "users" having "foo_email" > ?', $builder->toSql());
	}


	public function testRawHavings(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->havingRaw('user_foo < user_bar');
		$this->assertEquals('select * from "users" having user_foo < user_bar', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->having('baz', '=', 1)->orHavingRaw('user_foo < user_bar');
		$this->assertEquals('select * from "users" having "baz" = ? or user_foo < user_bar', $builder->toSql());
	}


	public function testLimitsAndOffsets(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->offset(5)->limit(10);
		$this->assertEquals('select * from "users" limit 10 offset 5', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->skip(5)->take(10);
		$this->assertEquals('select * from "users" limit 10 offset 5', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->skip(-5)->take(10);
		$this->assertEquals('select * from "users" limit 10 offset 0', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->forPage(2, 15);
		$this->assertEquals('select * from "users" limit 15 offset 15', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->forPage(-2, 15);
		$this->assertEquals('select * from "users" limit 15 offset 0', $builder->toSql());
	}


	public function testWhereShortcut(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('id', 1)->orWhere('name', 'foo');
		$this->assertEquals('select * from "users" where "id" = ? or "name" = ?', $builder->toSql());
		$this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
	}


	public function testNestedWheres(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('email', '=', 'foo')->orWhere(function($q)
		{
			$q->where('name', '=', 'bar')->where('age', '=', 25);
		});
		$this->assertEquals('select * from "users" where "email" = ? or ("name" = ? and "age" = ?)', $builder->toSql());
		$this->assertEquals([0 => 'foo', 1 => 'bar', 2 => 25], $builder->getBindings());
	}


	public function testFullSubSelects(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('email', '=', 'foo')->orWhere('id', '=', function($q)
		{
			$q->select(new Raw('max(id)'))->from('users')->where('email', '=', 'bar');
		});

		$this->assertEquals('select * from "users" where "email" = ? or "id" = (select max(id) from "users" where "email" = ?)', $builder->toSql());
		$this->assertEquals([0 => 'foo', 1 => 'bar'], $builder->getBindings());
	}


	public function testWhereExists(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('orders')->whereExists(function($q)
		{
			$q->select('*')->from('products')->where('products.id', '=', new Raw('"orders"."id"'));
		});
		$this->assertEquals('select * from "orders" where exists (select * from "products" where "products"."id" = "orders"."id")', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('orders')->whereNotExists(function($q)
		{
			$q->select('*')->from('products')->where('products.id', '=', new Raw('"orders"."id"'));
		});
		$this->assertEquals('select * from "orders" where not exists (select * from "products" where "products"."id" = "orders"."id")', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('orders')->where('id', '=', 1)->orWhereExists(function($q)
		{
			$q->select('*')->from('products')->where('products.id', '=', new Raw('"orders"."id"'));
		});
		$this->assertEquals('select * from "orders" where "id" = ? or exists (select * from "products" where "products"."id" = "orders"."id")', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('orders')->where('id', '=', 1)->orWhereNotExists(function($q)
		{
			$q->select('*')->from('products')->where('products.id', '=', new Raw('"orders"."id"'));
		});
		$this->assertEquals('select * from "orders" where "id" = ? or not exists (select * from "products" where "products"."id" = "orders"."id")', $builder->toSql());
	}


	public function testBasicJoins(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->join('contacts', 'users.id', '=', 'contacts.id')->leftJoin('photos', 'users.id', '=', 'photos.id');
		$this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" left join "photos" on "users"."id" = "photos"."id"', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->leftJoinWhere('photos', 'users.id', '=', 'bar')->joinWhere('photos', 'users.id', '=', 'foo');
		$this->assertEquals('select * from "users" left join "photos" on "users"."id" = ? inner join "photos" on "users"."id" = ?', $builder->toSql());
		$this->assertEquals(['bar', 'foo'], $builder->getBindings());
	}


	public function testComplexJoin(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->join('contacts', function($j)
		{
			$j->on('users.id', '=', 'contacts.id')->orOn('users.name', '=', 'contacts.name');
		});
		$this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" or "users"."name" = "contacts"."name"', $builder->toSql());

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->join('contacts', function($j)
		{
			$j->where('users.id', '=', 'foo')->orWhere('users.name', '=', 'bar');
		});
		$this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = ? or "users"."name" = ?', $builder->toSql());
		$this->assertEquals(['foo', 'bar'], $builder->getBindings());

		// Run the assertions again
		$this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = ? or "users"."name" = ?', $builder->toSql());
		$this->assertEquals(['foo', 'bar'], $builder->getBindings());
	}

	public function testJoinWhereNull(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->join('contacts', function($j)
		{
			$j->on('users.id', '=', 'contacts.id')->whereNull('contacts.deleted_at');
		});
		$this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" and "contacts"."deleted_at" is null', $builder->toSql());
	}

	public function testRawExpressionsInSelect(): void
    {
		$builder = $this->getBuilder();
		$builder->select(new Raw('substr(foo, 6)'))->from('users');
		$this->assertEquals('select substr(foo, 6) from "users"', $builder->toSql());
	}


	public function testFindReturnsFirstResultByID(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select * from "users" where "id" = ? limit 1', [1]
        )->andReturn([['foo' => 'bar']]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturnUsing(function($query, $results) { return $results; });
		$results = $builder->from('users')->find(1);
		$this->assertEquals(['foo' => 'bar'], $results);
	}


	public function testFirstMethodReturnsFirstResult(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select * from "users" where "id" = ? limit 1', [1]
        )->andReturn([['foo' => 'bar']]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturnUsing(function($query, $results) { return $results; });
		$results = $builder->from('users')->where('id', '=', 1)->first();
		$this->assertEquals(['foo' => 'bar'], $results);
	}


	public function testListMethodsGetsArrayOfColumnValues(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->andReturn([['foo' => 'bar'], ['foo' => 'baz']]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar'], ['foo' => 'baz']]
        )->andReturnUsing(function($query, $results)
		{
			return $results;
		});
		$results = $builder->from('users')->where('id', '=', 1)->lists('foo');
		$this->assertEquals(['bar', 'baz'], $results);

		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->andReturn(
            [['id' => 1, 'foo' => 'bar'], ['id' => 10, 'foo' => 'baz']]
        );
		$builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['id' => 1, 'foo' => 'bar'], ['id' => 10, 'foo' => 'baz']]
        )->andReturnUsing(function($query, $results)
		{
			return $results;
		});
		$results = $builder->from('users')->where('id', '=', 1)->lists('foo', 'id');
		$this->assertEquals([1 => 'bar', 10 => 'baz'], $results);
	}


	public function testImplode(): void
    {
		// Test without glue.
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->andReturn([['foo' => 'bar'], ['foo' => 'baz']]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar'], ['foo' => 'baz']]
        )->andReturnUsing(function($query, $results)
		{
			return $results;
		});
		$results = $builder->from('users')->where('id', '=', 1)->implode('foo');
		$this->assertEquals('barbaz', $results);

		// Test with glue.
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->andReturn([['foo' => 'bar'], ['foo' => 'baz']]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar'], ['foo' => 'baz']]
        )->andReturnUsing(function($query, $results)
		{
			return $results;
		});
		$results = $builder->from('users')->where('id', '=', 1)->implode('foo', ',');
		$this->assertEquals('bar,baz', $results);
	}


	public function testPaginateCorrectlyCreatesPaginatorInstance(): void
    {
		$connection = m::mock(ConnectionInterface::class);
		$grammar = m::mock(Grammar::class);
		$processor = m::mock(Processor::class);
		$builder = $this->getMock(Builder::class, ['getPaginationCount', 'forPage', 'get'], [$connection, $grammar, $processor]
        );
		$paginator = m::mock(Factory::class);
		$paginator->shouldReceive('getCurrentPage')->once()->andReturn(1);
		$connection->shouldReceive('getPaginator')->once()->andReturn($paginator);
		$builder->expects($this->once())->method('forPage')->with($this->equalTo(1), $this->equalTo(15))->willReturn(
            $builder
        );
		$builder->expects($this->once())->method('get')->with($this->equalTo(['*']))->willReturn(['foo']);
		$builder->expects($this->once())->method('getPaginationCount')->willReturn(10);
		$paginator->shouldReceive('make')->once()->with(['foo'], 10, 15)->andReturn(['results']);

		$this->assertEquals(['results'], $builder->paginate(15, ['*']));
	}


	public function testPaginateCorrectlyCreatesPaginatorInstanceForGroupedQuery(): void
    {
		$connection = m::mock(ConnectionInterface::class);
		$grammar = m::mock(Grammar::class);
		$processor = m::mock(Processor::class);
		$builder = $this->getMock(Builder::class, ['get'], [$connection, $grammar, $processor]);
		$paginator = m::mock(Factory::class);
		$paginator->shouldReceive('getCurrentPage')->once()->andReturn(2);
		$connection->shouldReceive('getPaginator')->once()->andReturn($paginator);
		$builder->expects($this->once())->method('get')->with($this->equalTo(['*']))->willReturn(
            ['foo', 'bar', 'baz']
        );
		$paginator->shouldReceive('make')->once()->with(['baz'], 3, 2)->andReturn(['results']);

		$this->assertEquals(['results'], $builder->groupBy('foo')->paginate(2, ['*']));
	}


	public function testGetPaginationCountGetsResultCount(): void
    {
		unset($_SERVER['orders']);
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function($query, $results)
		{
			$_SERVER['orders'] = $query->orders;
			return $results;
		});
		$results = $builder->from('users')->orderBy('foo', 'desc')->getPaginationCount();

		$this->assertNull($_SERVER['orders']);
		unset($_SERVER['orders']);

		$this->assertEquals([0 => ['column' => 'foo', 'direction' => 'desc']], $builder->orders);
		$this->assertEquals(1, $results);
	}


	public function testQuickPaginateCorrectlyCreatesPaginatorInstance(): void
    {
		$connection = m::mock(ConnectionInterface::class);
		$grammar = m::mock(Grammar::class);
		$processor = m::mock(Processor::class);
		$builder = $this->getMock(Builder::class, ['skip', 'take', 'get'], [$connection, $grammar, $processor]);
		$paginator = m::mock(Factory::class);
		$paginator->shouldReceive('getCurrentPage')->once()->andReturn(1);
		$connection->shouldReceive('getPaginator')->once()->andReturn($paginator);
		$builder->expects($this->once())->method('skip')->with($this->equalTo(0))->willReturn($builder);
		$builder->expects($this->once())->method('take')->with($this->equalTo(16))->willReturn($builder);
		$builder->expects($this->once())->method('get')->with($this->equalTo(['*']))->willReturn(['foo']);
		$paginator->shouldReceive('make')->once()->with(['foo'], 15)->andReturn(['results']);

		$this->assertEquals(['results'], $builder->simplePaginate(15, ['*']));
	}


	public function testPluckMethodReturnsSingleColumn(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select "foo" from "users" where "id" = ? limit 1', [1]
        )->andReturn([['foo' => 'bar']]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturn(
            [['foo' => 'bar']]
        );
		$results = $builder->from('users')->where('id', '=', 1)->pluck('foo');
		$this->assertEquals('bar', $results);
	}


	public function testAggregateFunctions(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function($builder, $results) { return $results; });
		$results = $builder->from('users')->count();
		$this->assertEquals(1, $results);

		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users" limit 1', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function($builder, $results) { return $results; });
		$results = $builder->from('users')->exists();
		$this->assertTrue($results);

		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select max("id") as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function($builder, $results) { return $results; });
		$results = $builder->from('users')->max('id');
		$this->assertEquals(1, $results);

		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select min("id") as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function($builder, $results) { return $results; });
		$results = $builder->from('users')->min('id');
		$this->assertEquals(1, $results);

		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select sum("id") as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function($builder, $results) { return $results; });
		$results = $builder->from('users')->sum('id');
		$this->assertEquals(1, $results);
	}


	public function testAggregateResetFollowedByGet(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getConnection()->shouldReceive('select')->once()->with('select sum("id") as aggregate from "users"', []
        )->andReturn([['aggregate' => 2]]);
		$builder->getConnection()->shouldReceive('select')->once()->with('select "column1", "column2" from "users"', [])->andReturn(
            [['column1' => 'foo', 'column2' => 'bar']]
        );
		$builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function($builder, $results) { return $results; });
		$builder->from('users')->select('column1', 'column2');
		$count = $builder->count();
		$this->assertEquals(1, $count);
		$sum = $builder->sum('id');
		$this->assertEquals(2, $sum);
		$result = $builder->get();
		$this->assertEquals([['column1' => 'foo', 'column2' => 'bar']], $result);
	}


	public function testAggregateResetFollowedBySelectGet(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select count("column1") as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getConnection()->shouldReceive('select')->once()->with('select "column2", "column3" from "users"', [])->andReturn(
            [['column2' => 'foo', 'column3' => 'bar']]
        );
		$builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function($builder, $results) { return $results; });
		$builder->from('users');
		$count = $builder->count('column1');
		$this->assertEquals(1, $count);
		$result = $builder->select('column2', 'column3')->get();
		$this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result);
	}


	public function testAggregateResetFollowedByGetWithColumns(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('select')->once()->with('select count("column1") as aggregate from "users"', []
        )->andReturn([['aggregate' => 1]]);
		$builder->getConnection()->shouldReceive('select')->once()->with('select "column2", "column3" from "users"', [])->andReturn(
            [['column2' => 'foo', 'column3' => 'bar']]
        );
		$builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function($builder, $results) { return $results; });
		$builder->from('users');
		$count = $builder->count('column1');
		$this->assertEquals(1, $count);
		$result = $builder->get(['column2', 'column3']);
		$this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result);
	}


	public function testInsertMethod(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email") values (?)', ['foo']
        )->andReturn(true);
		$result = $builder->from('users')->insert(['email' => 'foo']);
		$this->assertTrue($result);
	}


	public function testSQLiteMultipleInserts(): void
    {
		$builder = $this->getSQLiteBuilder();
		$builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email", "name") select ? as "email", ? as "name" union select ? as "email", ? as "name"', ['foo', 'taylor', 'bar', 'dayle']
        )->andReturn(true);
		$result = $builder->from('users')->insert(
            [['email' => 'foo', 'name' => 'taylor'], ['email' => 'bar', 'name' => 'dayle']]
        );
		$this->assertTrue($result);
	}


	public function testInsertGetIdMethod(): void
    {
		$builder = $this->getBuilder();
		$builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email") values (?)', ['foo'], 'id')->andReturn(1);
		$result = $builder->from('users')->insertGetId(['email' => 'foo'], 'id');
		$this->assertEquals(1, $result);
	}


	public function testInsertGetIdMethodRemovesExpressions(): void
    {
		$builder = $this->getBuilder();
		$builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email", "bar") values (?, bar)', ['foo'], 'id')->andReturn(1);
		$result = $builder->from('users')->insertGetId(
            ['email' => 'foo', 'bar' => new Illuminate\Database\Query\Expression('bar')], 'id');
		$this->assertEquals(1, $result);
	}


	public function testInsertMethodRespectsRawBindings(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email") values (CURRENT TIMESTAMP)', []
        )->andReturn(true);
		$result = $builder->from('users')->insert(['email' => new Raw('CURRENT TIMESTAMP')]);
		$this->assertTrue($result);
	}


	public function testUpdateMethod(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = ?, "name" = ? where "id" = ?', ['foo', 'bar', 1]
        )->andReturn(1);
		$result = $builder->from('users')->where('id', '=', 1)->update(['email' => 'foo', 'name' => 'bar']);
		$this->assertEquals(1, $result);

		$builder = $this->getMySqlBuilder();
		$builder->getConnection()->shouldReceive('update')->once()->with('update `users` set `email` = ?, `name` = ? where `id` = ? order by `foo` desc limit 5', ['foo', 'bar', 1]
        )->andReturn(1);
		$result = $builder->from('users')->where('id', '=', 1)->orderBy('foo', 'desc')->limit(5)->update(
            ['email' => 'foo', 'name' => 'bar']
        );
		$this->assertEquals(1, $result);
	}


	public function testUpdateMethodWithJoins(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('update')->once()->with('update "users" inner join "orders" on "users"."id" = "orders"."user_id" set "email" = ?, "name" = ? where "users"."id" = ?', ['foo', 'bar', 1]
        )->andReturn(1);
		$result = $builder->from('users')->join('orders', 'users.id', '=', 'orders.user_id')->where('users.id', '=', 1)->update(
            ['email' => 'foo', 'name' => 'bar']
        );
		$this->assertEquals(1, $result);
	}


	public function testUpdateMethodWithoutJoinsOnPostgres(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = ?, "name" = ? where "id" = ?', ['foo', 'bar', 1]
        )->andReturn(1);
		$result = $builder->from('users')->where('id', '=', 1)->update(['email' => 'foo', 'name' => 'bar']);
		$this->assertEquals(1, $result);
	}


	public function testUpdateMethodWithJoinsOnPostgres(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = ?, "name" = ? from "orders" where "users"."id" = ? and "users"."id" = "orders"."user_id"', ['foo', 'bar', 1]
        )->andReturn(1);
		$result = $builder->from('users')->join('orders', 'users.id', '=', 'orders.user_id')->where('users.id', '=', 1)->update(
            ['email' => 'foo', 'name' => 'bar']
        );
		$this->assertEquals(1, $result);
	}


	public function testUpdateMethodRespectsRaw(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = foo, "name" = ? where "id" = ?', ['bar', 1]
        )->andReturn(1);
		$result = $builder->from('users')->where('id', '=', 1)->update(['email' => new Raw('foo'), 'name' => 'bar']);
		$this->assertEquals(1, $result);
	}


	public function testDeleteMethod(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users" where "email" = ?', ['foo']
        )->andReturn(1);
		$result = $builder->from('users')->where('email', '=', 'foo')->delete();
		$this->assertEquals(1, $result);

		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users" where "id" = ?', [1])->andReturn(1);
		$result = $builder->from('users')->delete(1);
		$this->assertEquals(1, $result);
	}


	public function testDeleteWithJoinMethod(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->getConnection()->shouldReceive('delete')->once()->with('delete `users` from `users` inner join `contacts` on `users`.`id` = `contacts`.`id` where `email` = ?', ['foo']
        )->andReturn(1);
		$result = $builder->from('users')->join('contacts', 'users.id', '=', 'contacts.id')->where('email', '=', 'foo')->delete();
		$this->assertEquals(1, $result);

		$builder = $this->getMySqlBuilder();
		$builder->getConnection()->shouldReceive('delete')->once()->with('delete `users` from `users` inner join `contacts` on `users`.`id` = `contacts`.`id` where `id` = ?', [1]
        )->andReturn(1);
		$result = $builder->from('users')->join('contacts', 'users.id', '=', 'contacts.id')->delete(1);
		$this->assertEquals(1, $result);
	}


	public function testTruncateMethod(): void
    {
		$builder = $this->getBuilder();
		$builder->getConnection()->shouldReceive('statement')->once()->with('truncate "users"', []);
		$builder->from('users')->truncate();

		$sqlite = new Illuminate\Database\Query\Grammars\SQLiteGrammar;
		$builder = $this->getBuilder();
		$builder->from('users');
		$this->assertEquals([
			'delete from sqlite_sequence where name = ?' => ['users'],
			'delete from "users"' => [],
        ], $sqlite->compileTruncate($builder));
	}


	public function testPostgresInsertGetId(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email") values (?) returning "id"', ['foo'], 'id')->andReturn(1);
		$result = $builder->from('users')->insertGetId(['email' => 'foo'], 'id');
		$this->assertEquals(1, $result);
	}


	public function testMySqlWrapping(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('users');
		$this->assertEquals('select * from `users`', $builder->toSql());
	}


	public function testSQLiteOrderBy(): void
    {
		$builder = $this->getSQLiteBuilder();
		$builder->select('*')->from('users')->orderBy('email', 'desc');
		$this->assertEquals('select * from "users" order by "email" desc', $builder->toSql());
	}


	public function testSqlServerLimitsAndOffsets(): void
    {
		$builder = $this->getSqlServerBuilder();
		$builder->select('*')->from('users')->take(10);
		$this->assertEquals('select top 10 * from [users]', $builder->toSql());

		$builder = $this->getSqlServerBuilder();
		$builder->select('*')->from('users')->skip(10);
		$this->assertEquals('select * from (select *, row_number() over (order by (select 0)) as row_num from [users]) as temp_table where row_num >= 11', $builder->toSql());

		$builder = $this->getSqlServerBuilder();
		$builder->select('*')->from('users')->skip(10)->take(10);
		$this->assertEquals('select * from (select *, row_number() over (order by (select 0)) as row_num from [users]) as temp_table where row_num between 11 and 20', $builder->toSql());

		$builder = $this->getSqlServerBuilder();
		$builder->select('*')->from('users')->skip(10)->take(10)->orderBy('email', 'desc');
		$this->assertEquals('select * from (select *, row_number() over (order by [email] desc) as row_num from [users]) as temp_table where row_num between 11 and 20', $builder->toSql());
	}


	public function testMergeWheresCanMergeWheresAndBindings(): void
    {
		$builder = $this->getBuilder();
		$builder->wheres = ['foo'];
		$builder->mergeWheres(['wheres'], [12 => 'foo', 13 => 'bar']);
		$this->assertEquals(['foo', 'wheres'], $builder->wheres);
		$this->assertEquals(['foo', 'bar'], $builder->getBindings());
	}


	public function testProvidingNullOrFalseAsSecondParameterBuildsCorrectly(): void
    {
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->where('foo', null);
		$this->assertEquals('select * from "users" where "foo" is null', $builder->toSql());
	}


	public function testDynamicWhere(): void
    {
		$method     = 'whereFooBarAndBazOrQux';
		$parameters = ['corge', 'waldo', 'fred'];
		$builder    = m::mock(Builder::class)->makePartial();

		$builder->shouldReceive('where')->with('foo_bar', '=', $parameters[0], 'and')->once()->andReturn($builder);
		$builder->shouldReceive('where')->with('baz', '=', $parameters[1], 'and')->once()->andReturn($builder);
		$builder->shouldReceive('where')->with('qux', '=', $parameters[2], 'or')->once()->andReturn($builder);

		$this->assertEquals($builder, $builder->dynamicWhere($method, $parameters));
	}


	public function testDynamicWhereIsNotGreedy(): void
    {
		$method     = 'whereIosVersionAndAndroidVersionOrOrientation';
		$parameters = ['6.1', '4.2', 'Vertical'];
		$builder    = m::mock(Builder::class)->makePartial();

		$builder->shouldReceive('where')->with('ios_version', '=', '6.1', 'and')->once()->andReturn($builder);
		$builder->shouldReceive('where')->with('android_version', '=', '4.2', 'and')->once()->andReturn($builder);
		$builder->shouldReceive('where')->with('orientation', '=', 'Vertical', 'or')->once()->andReturn($builder);

		$builder->dynamicWhere($method, $parameters);
	}


	public function testCallTriggersDynamicWhere(): void
    {
		$builder = $this->getBuilder();

		$this->assertEquals($builder, $builder->whereFooAndBar('baz', 'qux'));
		$this->assertCount(2, $builder->wheres);
	}


    public function testBuilderThrowsExpectedExceptionWithUndefinedMethod(): void
    {
        $this->expectException(BadMethodCallException::class);
        $builder = $this->getBuilder();

        $builder->noValidMethodHere();
    }


	public function setupCacheTestQuery($cache, $driver): Builder
    {
		$connection = m::mock(ConnectionInterface::class);
		$connection->shouldReceive('getName')->andReturn('connection_name');
		$connection->shouldReceive('getCacheManager')->once()->andReturn($cache);
		$cache->shouldReceive('driver')->once()->andReturn($driver);
		$grammar = new Illuminate\Database\Query\Grammars\Grammar;
		$processor = m::mock(Processor::class);

		$builder = $this->getMock(Builder::class, ['getFresh'], [$connection, $grammar, $processor]);
		$builder->expects($this->once())->method('getFresh')->with($this->equalTo(['*']))->willReturn(
            ['results']
        );
		return $builder->select('*')->from('users')->where('email', 'foo@bar.com');
	}


	public function testMySqlLock(): void
    {
		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock();
		$this->assertEquals('select * from `foo` where `bar` = ? for update', $builder->toSql());
		$this->assertEquals(['baz'], $builder->getBindings());

		$builder = $this->getMySqlBuilder();
		$builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock(false);
		$this->assertEquals('select * from `foo` where `bar` = ? lock in share mode', $builder->toSql());
		$this->assertEquals(['baz'], $builder->getBindings());
	}


	public function testPostgresLock(): void
    {
		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock();
		$this->assertEquals('select * from "foo" where "bar" = ? for update', $builder->toSql());
		$this->assertEquals(['baz'], $builder->getBindings());

		$builder = $this->getPostgresBuilder();
		$builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock(false);
		$this->assertEquals('select * from "foo" where "bar" = ? for share', $builder->toSql());
		$this->assertEquals(['baz'], $builder->getBindings());
	}


	public function testSqlServerLock(): void
    {
		$builder = $this->getSqlServerBuilder();
		$builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock();
		$this->assertEquals('select * from [foo] with(rowlock,updlock,holdlock) where [bar] = ?', $builder->toSql());
		$this->assertEquals(['baz'], $builder->getBindings());

		$builder = $this->getSqlServerBuilder();
		$builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock(false);
		$this->assertEquals('select * from [foo] with(rowlock,holdlock) where [bar] = ?', $builder->toSql());
		$this->assertEquals(['baz'], $builder->getBindings());
	}


	public function testBindingOrder(): void
    {
		$expectedSql = 'select * from "users" inner join "othertable" on "bar" = ? where "registered" = ? group by "city" having "population" > ? order by match ("foo") against(?)';
		$expectedBindings = ['foo', 1, 3, 'bar'];

		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->join('othertable', function($join) { $join->where('bar', '=', 'foo'); })->where('registered', 1)->groupBy('city')->having('population', '>', 3)->orderByRaw('match ("foo") against(?)', ['bar']
        );
		$this->assertEquals($expectedSql, $builder->toSql());
		$this->assertEquals($expectedBindings, $builder->getBindings());

		// order of statements reversed
		$builder = $this->getBuilder();
		$builder->select('*')->from('users')->orderByRaw('match ("foo") against(?)', ['bar'])->having('population', '>', 3)->groupBy('city')->where('registered', 1)->join('othertable', function($join) { $join->where('bar', '=', 'foo'); });
		$this->assertEquals($expectedSql, $builder->toSql());
		$this->assertEquals($expectedBindings, $builder->getBindings());
	}


	public function testAddBindingWithArrayMergesBindings(): void
    {
		$builder = $this->getBuilder();
		$builder->addBinding(['foo', 'bar']);
		$builder->addBinding(['baz']);
		$this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
	}


	public function testAddBindingWithArrayMergesBindingsInCorrectOrder(): void
    {
		$builder = $this->getBuilder();
		$builder->addBinding(['bar', 'baz'], 'having');
		$builder->addBinding(['foo'], 'where');
		$this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
	}


	public function testMergeBuilders(): void
    {
		$builder = $this->getBuilder();
		$builder->addBinding(['foo', 'bar']);
		$otherBuilder = $this->getBuilder();
		$otherBuilder->addBinding(['baz']);
		$builder->mergeBindings($otherBuilder);
		$this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
	}


	public function testMergeBuildersBindingOrder(): void
    {
		$builder = $this->getBuilder();
		$builder->addBinding('foo', 'where');
		$builder->addBinding('baz', 'having');
		$otherBuilder = $this->getBuilder();
		$otherBuilder->addBinding('bar', 'where');
		$builder->mergeBindings($otherBuilder);
		$this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
	}

    public function testChunkByIdOnArrays(): void
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = [['someIdField' => 1], ['someIdField' => 2]];
        $chunk2 = [['someIdField' => 10], ['someIdField' => 11]];
        $chunk3 = [];
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 11, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('get')->times(3)->andReturn($chunk1, $chunk2, $chunk3);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk2);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk3);

        $builder->chunkById(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    public function testChunkPaginatesUsingIdWithLastChunkComplete(): void
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = [(object) ['someIdField' => 1], (object) ['someIdField' => 2]];
        $chunk2 = [(object) ['someIdField' => 10], (object) ['someIdField' => 11]];
        $chunk3 = [];
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 11, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('get')->times(3)->andReturn($chunk1, $chunk2, $chunk3);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk2);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk3);

        $builder->chunkById(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    public function testChunkPaginatesUsingIdWithLastChunkPartial(): void
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = [(object) ['someIdField' => 1], (object) ['someIdField' => 2]];
        $chunk2 = [(object) ['someIdField' => 10]];
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('get')->times(2)->andReturn($chunk1, $chunk2);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk2);

        $builder->chunkById(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    public function testChunkPaginatesUsingIdWithCountZero(): void
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk = [];
        $builder->shouldReceive('forPageAfterId')->once()->with(0, 0, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('get')->times(1)->andReturn($chunk);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->never();

        $builder->chunkById(0, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    public function testChunkPaginatesUsingIdWithAlias(): void
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = [(object) ['table_id' => 1], (object) ['table_id' => 10]];
        $chunk2 = [];
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 0, 'table.id')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 10, 'table.id')->andReturnSelf();
        $builder->shouldReceive('get')->times(2)->andReturn($chunk1, $chunk2);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk2);

        $builder->chunkById(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'table.id', 'table_id');
    }

	protected function getBuilder(): Builder
    {
		$grammar = new Illuminate\Database\Query\Grammars\Grammar;
		$processor = m::mock(Processor::class);
		return new Builder(m::mock(ConnectionInterface::class), $grammar, $processor);
	}


	protected function getPostgresBuilder(): Builder
    {
		$grammar = new Illuminate\Database\Query\Grammars\PostgresGrammar;
		$processor = m::mock(Processor::class);
		return new Builder(m::mock(ConnectionInterface::class), $grammar, $processor);
	}


	protected function getMySqlBuilder(): Builder
    {
		$grammar = new Illuminate\Database\Query\Grammars\MySqlGrammar;
		$processor = m::mock(Processor::class);
		return new Builder(m::mock(ConnectionInterface::class), $grammar, $processor);
	}


	protected function getSQLiteBuilder(): Builder
    {
		$grammar = new Illuminate\Database\Query\Grammars\SQLiteGrammar;
		$processor = m::mock(Processor::class);
		return new Builder(m::mock(ConnectionInterface::class), $grammar, $processor);
	}


	protected function getSqlServerBuilder(): Builder
    {
		$grammar = new Illuminate\Database\Query\Grammars\SqlServerGrammar;
		$processor = m::mock(Processor::class);
		return new Builder(m::mock(ConnectionInterface::class), $grammar, $processor);
	}


	protected function getMySqlBuilderWithProcessor(): Builder
    {
		$grammar = new Illuminate\Database\Query\Grammars\MySqlGrammar;
		$processor = new Illuminate\Database\Query\Processors\MySqlProcessor;
		return new Builder(m::mock(ConnectionInterface::class), $grammar, $processor);
	}

    /**
     * @return MockInterface|\Illuminate\Database\Query\Builder
     */
    protected function getMockQueryBuilder(): MockInterface|Builder
    {
        return m::mock(Builder::class, [
            m::mock(ConnectionInterface::class),
            new Grammar,
            m::mock(Processor::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();
    }

}
