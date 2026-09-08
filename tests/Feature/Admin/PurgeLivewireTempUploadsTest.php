<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeLivewireTempUploadsTest extends TestCase
{
    public function test_deletes_files_older_than_24_hours_and_keeps_recent_ones(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('livewire-tmp/old-file.pdf', 'content');
        Storage::disk('local')->put('livewire-tmp/recent-file.pdf', 'content');

        touch(Storage::disk('local')->path('livewire-tmp/old-file.pdf'), now()->subDay()->subMinute()->timestamp);

        $this->artisan('rag:purge-livewire-tmp')->assertSuccessful();

        Storage::disk('local')->assertMissing('livewire-tmp/old-file.pdf');
        Storage::disk('local')->assertExists('livewire-tmp/recent-file.pdf');
    }
}
