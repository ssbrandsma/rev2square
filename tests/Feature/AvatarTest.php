<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private function test_image(int $width = 600, int $height = 400): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($image, 200, 30, 30);
        imagefill($image, 0, 0, $red);

        $path = tempnam(sys_get_temp_dir(), 'avatar').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
    }

    public function test_upload_resizes_reencodes_and_replaces(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('avatars.store'), ['avatar' => $this->test_image()])
            ->assertRedirect();

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        $info = getimagesize(Storage::disk('local')->path($path));
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(256, max($info[0], $info[1]));

        $this->get(route('avatars.show', $user))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        // A second upload replaces the first file.
        $this->actingAs($user)
            ->post(route('avatars.store'), ['avatar' => $this->test_image(100, 100)])
            ->assertRedirect();

        Storage::disk('local')->assertMissing($path);
        $this->assertNotSame($path, $user->fresh()->avatar_path);
    }

    public function test_invalid_uploads_rejected_and_delete_resets(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('avatars.store'), [
                'avatar' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->actingAs($user)
            ->post(route('avatars.store'), ['avatar' => 'not-a-file'])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->get(route('avatars.show', $user))->assertNotFound();

        $this->actingAs($user)
            ->post(route('avatars.store'), ['avatar' => $this->test_image()])
            ->assertRedirect();

        $path = $user->fresh()->avatar_path;

        $this->actingAs($user)
            ->delete(route('avatars.destroy'))
            ->assertRedirect();

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
        $this->get(route('avatars.show', $user))->assertNotFound();
    }
}
