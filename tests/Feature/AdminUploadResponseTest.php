<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUploadResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/admin/_upload-test', function (Request $request) {
            $request->validate(['title' => 'required|string']);

            return redirect('/admin/_upload-result')->with('success', 'Fichier enregistré');
        });
        Route::middleware('web')->put('/admin/_upload-test', function () {
            return redirect('/admin/_upload-result')->with('success', 'Fichier modifié');
        });
        Route::middleware('web')->get('/admin/_upload-result', function (Request $request) {
            return response()->json(['message' => $request->session()->get('success')]);
        });
    }

    public function test_upload_redirect_keeps_flash_until_the_browser_opens_the_destination_once(): void
    {
        $this->withHeaders(['X-Admin-Upload' => '1', 'Accept' => 'application/json'])
            ->post('/admin/_upload-test', ['title' => 'Mon document'])
            ->assertOk()
            ->assertJson(['redirect' => url('/admin/_upload-result')]);

        $this->get('/admin/_upload-result')->assertOk()->assertJson(['message' => 'Fichier enregistré']);
        $this->get('/admin/_upload-result')->assertOk()->assertJson(['message' => null]);
    }

    public function test_normal_forms_keep_their_native_redirect(): void
    {
        $this->post('/admin/_upload-test', ['title' => 'Mon document'])
            ->assertRedirect('/admin/_upload-result')
            ->assertSessionHas('success', 'Fichier enregistré');
    }

    public function test_upload_validation_returns_errors_without_a_redirect(): void
    {
        $this->withHeaders(['X-Admin-Upload' => '1', 'Accept' => 'application/json'])
            ->post('/admin/_upload-test', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_method_spoofed_update_also_returns_a_browser_redirect(): void
    {
        $this->withHeaders(['X-Admin-Upload' => '1', 'Accept' => 'application/json'])
            ->post('/admin/_upload-test', ['_method' => 'PUT'])
            ->assertOk()
            ->assertJson(['redirect' => url('/admin/_upload-result')]);
    }
}
