<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;

class UsersApiTest extends TestCase
{
    use RefreshDatabase;


    public function testNonAdminUsersCanOnlyViewBasicUserInfo()
    {
        // create a non-admin user
        $nonAdminUser = User::factory()->create();
        $nonAdminUser->revokePermission('admin/users');

        // create an admin user
        $adminUser = User::factory()->create();
        $adminUser->givePermission('admin/users');

        // ensure the non-admin user can access the endpoint and see basic user info only
        $response = $this->actingAs($nonAdminUser)->get('/api/users');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'users' => [
                '*' => [
                    'id',
                    'display_name'
                ]
            ],
            'total'
        ]);

        $response->assertJsonMissing([
            'users' => [
                '*' => [
                    'email',
                    'password'
                ]
            ],
        ]);
        $response->assertJsonFragment([
            'id' => $nonAdminUser->id,
            'email' => $nonAdminUser->email
        ]);

        // ensure the admin user can access the endpoint and see additional user info
        $response = $this->actingAs($adminUser)->get('/api/users');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'users' => [
                '*' => [
                    'id',
                    'email'
                ]
            ],
            'total'
        ]);
        $response->assertJsonMissing([
            'users' => [
                '*' => [
                    'password'
                ]
            ]
        ]);
        $response->assertJsonFragment([
            'id' => $nonAdminUser->id,
            'email' => $nonAdminUser->email
        ]);
    }

    public function testGuestCannotCreateUser()
    {
        $userData = [
            'email' => 'johndoe@example.com',
            'password' => 'password',
        ];

        $response = $this->postJson('/api/users', $userData);
        $response->assertStatus(401);
        $this->assertDatabaseMissing('users', [
            'email' => $userData['email'],
        ]);
    }

    public function testGuestCanRegisterUser()
    {
        $userData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'display_name' => 'jackdoe',
            'email' => 'johndoe@example.com',
            'password' => 'password',
        ];

        $response = $this->postJson('/api/register', $userData);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'email' => $userData['email'],
        ]);
    }

    public function testCannotCreateDuplicateEmailOrDisplayName()
    {
        $userData = [
            'display_name' => 'JackDoe',
            'first_name' => 'Jack',
            'last_name' => 'Doe',
            'email' => 'jackdoe@example.com',
            'password' => 'password',
        ];

        // Test creating user
        $response = $this->postJson('/api/register', $userData);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'email' => 'jackdoe@example.com',
        ]);

        // Test creating duplicate user
        $response = $this->postJson('/api/register', $userData);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['display_name', 'email']);
    }

    public function testUserCanOnlyUpdateOwnUser()
    {
        $user = User::factory()->create();

        $userData = [
            'email' => 'raffi@example.com',
            'password' => 'password',
        ];

        // Test updating own user
        $response = $this->actingAs($user)->putJson('/api/users/' . $user->id, $userData);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'raffi@example.com',
        ]);

        // Test updating another user
        $otherUser = User::factory()->create();
        $otherUserData = [
            'email' => 'otherraffi@example.com',
            'password' => 'password',
        ];

        $response = $this->actingAs($user)->putJson('/api/users/' . $otherUser->id, $otherUserData);
        $response->assertStatus(403);
    }

    public function testUserCannotDeleteUsers()
    {
        $user = User::factory()->create();

        // Test deleting own user
        $response = $this->actingAs($user)->deleteJson('/api/users/' . $user->id);
        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        // Test deleting another user
        $otherUser = User::factory()->create();
        $response = $this->actingAs($user)->deleteJson('/api/users/' . $otherUser->id);
        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
    }

    public function testAdminCanUpdateAnyUser()
    {
        $admin = User::factory()->create();
        $admin->givePermission('admin/users');

        $user = User::factory()->create();

        $userData = [
            'email' => 'todddoe@example.com',
            'password' => 'password',
        ];

        // Test updating own user
        $response = $this->actingAs($admin)->putJson('/api/users/' . $user->id, $userData);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'todddoe@example.com'
        ]);

        // Test updating another user
        $otherUser = User::factory()->create();
        $otherUserData = [
            'email' => 'kimdoe@example.com',
            'password' => 'password',
        ];

        $response = $this->actingAs($admin)->putJson('/api/users/' . $otherUser->id, $otherUserData);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'email' => 'kimdoe@example.com',
        ]);
    }

    public function testAdminCanDeleteAnyUser()
    {
        $admin = User::factory()->create();
        $admin->givePermission('admin/users');

        $user = User::factory()->create();

        // Test deleting own user
        $response = $this->actingAs($admin)->deleteJson('/api/users/' . $user->id);
        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        // Test deleting another user
        $otherUser = User::factory()->create();
        $response = $this->actingAs($admin)->deleteJson('/api/users/' . $otherUser->id);
        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $otherUser->id]);
    }
}
