<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_upload_an_avatar()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
                'avatar' => $file,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();
        $this->assertNotNull($user->avatar);
        Storage::disk('public')->assertExists($user->avatar);
    }

    public function test_user_can_replace_and_delete_old_avatar()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $oldFile = UploadedFile::fake()->image('old.jpg');

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Test User',
            'email' => $user->email,
            'avatar' => $oldFile,
        ]);

        $oldPath = $user->refresh()->avatar;
        Storage::disk('public')->assertExists($oldPath);

        $newFile = UploadedFile::fake()->image('new.jpg');
        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Test User',
            'email' => $user->email,
            'avatar' => $newFile,
        ]);

        $newPath = $user->refresh()->avatar;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_user_can_remove_avatar()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg');

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Test User',
            'email' => $user->email,
            'avatar' => $file,
        ]);

        $path = $user->refresh()->avatar;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Test User',
            'email' => $user->email,
            'remove_avatar' => true,
        ]);

        $this->assertNull($user->refresh()->avatar);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_non_image_avatar_is_rejected()
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 500);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
                'avatar' => $file,
            ]);

        $response->assertSessionHasErrors('avatar');
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->fresh());
    }
}
