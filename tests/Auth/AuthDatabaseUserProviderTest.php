<?php

use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class AuthDatabaseUserProviderTest extends BackwardCompatibleTestCase
{

    protected function tearDown(): void
    {
        m::close();
    }


    public function testRetrieveByIDReturnsUserWhenUserIsFound()
    {
        $conn = m::mock(\Illuminate\Database\Connection::class);
        $conn->shouldReceive('table')->once()->with('foo')->andReturn($conn);
        $conn->shouldReceive('find')->once()->with(1)->andReturn(array('id' => 1, 'name' => 'Dayle'));
		$hasher = m::mock(\Illuminate\Hashing\HasherInterface::class);
		$provider = new Illuminate\Auth\DatabaseUserProvider($conn, $hasher, 'foo');
		$user = $provider->retrieveByID(1);

		$this->assertInstanceOf(\Illuminate\Auth\GenericUser::class, $user);
		$this->assertEquals(1, $user->getAuthIdentifier());
		$this->assertEquals('Dayle', $user->name);
	}


	public function testRetrieveByIDReturnsNullWhenUserIsNotFound()
	{
		$conn = m::mock(\Illuminate\Database\Connection::class);
		$conn->shouldReceive('table')->once()->with('foo')->andReturn($conn);
		$conn->shouldReceive('find')->once()->with(1)->andReturn(null);
		$hasher = m::mock(\Illuminate\Hashing\HasherInterface::class);
		$provider = new Illuminate\Auth\DatabaseUserProvider($conn, $hasher, 'foo');
		$user = $provider->retrieveByID(1);

		$this->assertNull($user);
	}


	public function testRetrieveByCredentialsReturnsUserWhenUserIsFound()
	{
		$conn = m::mock(\Illuminate\Database\Connection::class);
		$conn->shouldReceive('table')->once()->with('foo')->andReturn($conn);
		$conn->shouldReceive('where')->once()->with('username', 'dayle');
		$conn->shouldReceive('first')->once()->andReturn(array('id' => 1, 'name' => 'taylor'));
		$hasher = m::mock(\Illuminate\Hashing\HasherInterface::class);
		$provider = new Illuminate\Auth\DatabaseUserProvider($conn, $hasher, 'foo');
		$user = $provider->retrieveByCredentials(array('username' => 'dayle', 'password' => 'foo'));

		$this->assertInstanceOf(\Illuminate\Auth\GenericUser::class, $user);
		$this->assertEquals(1, $user->getAuthIdentifier());
		$this->assertEquals('taylor', $user->name);
	}


	public function testRetrieveByCredentialsReturnsNullWhenUserIsFound()
	{
		$conn = m::mock(\Illuminate\Database\Connection::class);
		$conn->shouldReceive('table')->once()->with('foo')->andReturn($conn);
		$conn->shouldReceive('where')->once()->with('username', 'dayle');
		$conn->shouldReceive('first')->once()->andReturn(null);
		$hasher = m::mock(\Illuminate\Hashing\HasherInterface::class);
		$provider = new Illuminate\Auth\DatabaseUserProvider($conn, $hasher, 'foo');
		$user = $provider->retrieveByCredentials(array('username' => 'dayle'));

		$this->assertNull($user);
	}


	public function testCredentialValidation()
	{
		$conn = m::mock(\Illuminate\Database\Connection::class);
		$hasher = m::mock(\Illuminate\Hashing\HasherInterface::class);
		$hasher->shouldReceive('check')->once()->with('plain', 'hash')->andReturn(true);
		$provider = new Illuminate\Auth\DatabaseUserProvider($conn, $hasher, 'foo');
		$user = m::mock(\Illuminate\Auth\UserInterface::class);
		$user->shouldReceive('getAuthPassword')->once()->andReturn('hash');
		$result = $provider->validateCredentials($user, array('password' => 'plain'));

		$this->assertTrue($result);
	}

}
