<?php

namespace Tests\Feature;

use App\Models\CategoryAttribute;
use App\Models\ExtensionDevice;
use App\Models\ExtensionMessageTemplate;
use App\Models\ExtensionPairing;
use App\Models\Marketplace;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProductCsvPreviewService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChromeExtensionApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_product_edit_shows_supported_marketplace_shortcuts(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $product = $owner->products()->create([
            'internal_sku' => 'FD-SHORTCUT-01',
            'product_name' => 'Shortcut test item',
            'condition' => 'used_good',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $response = $this->actingAs($owner)->get(route('products.edit', $product));
        $response->assertOk();

        foreach ([
            'mercari' => ['https://jp.mercari.com/sell/create', 'メルカリ'],
            'rakuma' => ['https://fril.jp/item/new', 'ラクマ'],
            'yahoo_flea_market' => ['https://paypayfleamarket-sec.yahoo.co.jp/item/add?from=sellTop', 'Yahoo!フリマ'],
            'yahoo_auctions' => ['https://auctions.yahoo.co.jp/sell/jp/show/submit?category=0', 'Yahoo!オークション'],
        ] as $platformId => [$url, $name]) {
            $response->assertSee('data-marketplace-shortcut="'.$platformId.'"', false)
                ->assertSee('href="'.$url.'"', false)
                ->assertSee($name.'の出品画面を開く');
        }
    }

    public function test_pairing_is_user_approved_and_product_search_is_scoped_and_revocable(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $owner->products()->create([
            'internal_sku' => 'FD-OWN-01',
            'product_name' => 'Owner jacket',
            'condition' => 'used_good',
            'description_base' => 'Owner description',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $other->products()->create([
            'internal_sku' => 'FD-OTHER-01',
            'product_name' => 'Other jacket',
            'condition' => 'used_good',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $start = $this->postJson('/api/extension/v1/pairing/start', [
            'code_challenge' => $challenge,
            'redirect_uri' => 'https://'.str_repeat('a', 32).'.chromiumapp.org/pair',
            'device_name' => 'Feature test extension',
        ])->assertCreated()->json();

        $this->actingAs($owner)
            ->get($start['approval_url'])->assertOk()->assertSee('この端末を連携しますか？');
        $approval = $this->actingAs($owner)->post($start['approval_url']);
        $approval->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_FRAGMENT), $callback);

        $connection = $this->postJson('/api/extension/v1/pairing/complete', [
            'pairing_id' => $start['pairing_id'],
            'code' => $callback['code'],
            'verifier' => $verifier,
        ])->assertOk()->json();

        $token = $connection['access_token'];
        $device = ExtensionDevice::query()->where('user_id', $owner->id)->firstOrFail();
        $this->assertNotSame($token, $device->token_hash);
        $this->assertSame(ExtensionDevice::SCOPES, $device->scopes);
        $this->withToken($token)->getJson('/api/extension/v1/products?query=FD-OWN')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.internal_sku', 'FD-OWN-01')
            ->assertJsonMissing(['internal_sku' => 'FD-OTHER-01']);

        foreach ([
            'mercari' => 'メルカリ',
            'rakuma' => 'ラクマ',
            'yahoo_flea_market' => 'Yahoo!フリマ',
            'yahoo_auctions' => 'Yahoo!オークション',
            'mercari_shops' => 'メルカリShops',
        ] as $platform => $platformName) {
            $this->withToken($token)->getJson('/api/extension/v1/products/1/draft?platform='.$platform)
                ->assertOk()
                ->assertJsonPath('platform.id', $platform)
                ->assertJsonPath('platform.name', $platformName)
                ->assertJsonPath('authorized_publish', false)
                ->assertJsonPath('platform.operation_level', 'manual_assist');
        }

        $this->withToken($token)->postJson('/api/extension/v1/pairing/revoke')->assertOk()->assertJsonPath('revoked', true);
        $this->withToken($token)->getJson('/api/extension/v1/me')->assertUnauthorized();
        $device = ExtensionDevice::query()->where('user_id', $owner->id)->firstOrFail();
        $this->assertNotNull($device->revoked_at);
    }

    public function test_pairing_rejects_redirects_outside_the_chrome_identity_origin(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->postJson('/api/extension/v1/pairing/start', [
            'code_challenge' => $challenge,
            'redirect_uri' => 'https://attacker.example/callback',
            'device_name' => 'Unknown extension',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('extension_pairings', 0, 'furimadeck');
    }

    public function test_production_pairing_requires_the_configured_extension_identity(): void
    {
        config(['app.env' => 'production', 'chrome_extension.extension_id' => str_repeat('a', 32)]);
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->postJson('/api/extension/v1/pairing/start', [
            'code_challenge' => $challenge,
            'redirect_uri' => 'https://'.str_repeat('b', 32).'.chromiumapp.org/pair',
            'device_name' => 'Untrusted extension',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('extension_pairings', 0, 'furimadeck');

        $this->postJson('/api/extension/v1/pairing/start', [
            'code_challenge' => $challenge,
            'redirect_uri' => 'https://'.str_repeat('a', 32).'.chromiumapp.org/pair',
            'device_name' => 'Trusted extension',
        ])->assertCreated();
    }

    public function test_production_pairing_fails_closed_when_extension_identity_is_not_configured(): void
    {
        config(['app.env' => 'production', 'chrome_extension.extension_id' => '']);
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->postJson('/api/extension/v1/pairing/start', [
            'code_challenge' => $challenge,
            'redirect_uri' => 'https://'.str_repeat('a', 32).'.chromiumapp.org/pair',
            'device_name' => 'Unconfigured extension',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('extension_pairings', 0, 'furimadeck');
    }

    public function test_extension_draft_includes_saved_shipping_and_fee_values_without_guessing_them(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $category = ProductCategory::query()->create(['name' => 'トップス', 'slug' => 'tops']);
        $product = $owner->products()->create([
            'internal_sku' => 'FD-DRAFT-01',
            'product_name' => 'Vintage jacket',
            'category_id' => $category->id,
            'condition' => 'used_good',
            'quantity_available' => 5,
            'inventory_status' => 'in_stock',
        ]);
        $brand = CategoryAttribute::query()->create([
            'category_id' => $category->id,
            'key' => 'brand',
            'label' => 'ブランド',
            'input_type' => 'text',
        ]);
        $product->attributeValues()->create(['category_attribute_id' => $brand->id, 'value_text' => 'サンプルブランド']);
        $marketplace = Marketplace::query()->create([
            'code' => 'mercari',
            'name' => 'メルカリ',
            'base_url' => 'https://jp.mercari.com',
            'is_active' => true,
            'default_fee_rate' => 10,
        ]);
        $owner->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => 'Vintage jacket listing',
            'listing_price' => 5000,
            'listing_quantity' => 1,
            'expected_fee_rate' => 9.5,
            'shipping_method' => 'らくらくメルカリ便',
            'shipping_fee' => 210,
            'shipping_payer' => 'seller',
            'sender_region' => '広島県',
            'dispatch_days' => 2,
            'shipping_size' => '60サイズ',
            'shipping_weight_grams' => 800,
            'sale_format' => 'fixed_price',
            'listing_period_days' => 30,
            'return_policy' => '返品不可',
            'purchase_application' => 'disabled',
            'status' => 'active',
        ]);
        $token = $this->createExtensionDeviceToken($owner);

        $this->withToken($token)->getJson('/api/extension/v1/products/'.$product->id.'/draft?platform=mercari')
            ->assertOk()
            ->assertJsonPath('product.shipping_method', 'らくらくメルカリ便')
            ->assertJsonPath('product.shipping_fee', 210)
            ->assertJsonPath('product.shipping_payer', 'seller')
            ->assertJsonPath('product.sender_region', '広島県')
            ->assertJsonPath('product.dispatch_days', 2)
            ->assertJsonPath('product.shipping_size', '60サイズ')
            ->assertJsonPath('product.shipping_weight_grams', 800)
            ->assertJsonPath('product.sale_format', 'fixed_price')
            ->assertJsonPath('product.listing_period_days', 30)
            ->assertJsonPath('product.return_policy', '返品不可')
            ->assertJsonPath('product.purchase_application', 'disabled')
            ->assertJsonPath('product.listing_quantity', 1)
            ->assertJsonPath('product.quantity_available', 5)
            ->assertJsonPath('product.attributes.0.label', 'ブランド')
            ->assertJsonPath('product.attributes.0.value', 'サンプルブランド')
            ->assertJsonPath('product.sales_fee_rate', '9.50');
    }

    public function test_extension_draft_uses_the_selected_marketplace_listing_values(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $product = $owner->products()->create([
            'internal_sku' => 'FD-CHANNEL-01',
            'product_name' => 'Shared product name',
            'condition' => 'used_good',
            'description_base' => 'Shared fallback description',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $platforms = [
            'mercari' => 'メルカリ',
            'rakuma' => 'ラクマ',
            'yahoo_flea_market' => 'Yahoo!フリマ',
            'yahoo_auctions' => 'Yahoo!オークション',
            'mercari_shops' => 'メルカリShops',
        ];
        $token = $this->createExtensionDeviceToken($owner);

        foreach ($platforms as $code => $name) {
            $marketplace = Marketplace::query()->create([
                'code' => $code,
                'name' => $name,
                'base_url' => 'https://example.test/'.$code,
                'is_active' => true,
                'default_fee_rate' => 10,
            ]);
            $owner->listings()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => $name.'専用タイトル',
                'listing_description' => $name.'専用説明',
                'listing_price' => 1000 + $marketplace->id,
                'listing_quantity' => 1,
                'expected_fee_rate' => 5 + $marketplace->id,
                'marketplace_category' => $name.'専用カテゴリ',
                'marketplace_condition' => $name.'専用状態',
                'shipping_method' => $name.'専用配送方法',
                'shipping_fee' => 200 + $marketplace->id,
                'status' => 'active',
            ]);
        }

        foreach ($platforms as $code => $name) {
            $listing = $owner->listings()->whereHas('marketplace', fn ($query) => $query->where('code', $code))->firstOrFail();

            $this->withToken($token)->getJson('/api/extension/v1/products/'.$product->id.'/draft?platform='.$code)
                ->assertOk()
                ->assertJsonPath('platform.id', $code)
                ->assertJsonPath('platform.name', $name)
                ->assertJsonPath('product.title', $name.'専用タイトル')
                ->assertJsonPath('product.description', $name.'専用説明')
                ->assertJsonPath('product.price', (int) $listing->listing_price)
                ->assertJsonPath('product.category', $name.'専用カテゴリ')
                ->assertJsonPath('product.condition', $name.'専用状態')
                ->assertJsonPath('product.shipping_method', $name.'専用配送方法')
                ->assertJsonPath('product.shipping_fee', (int) $listing->shipping_fee)
                ->assertJsonPath('product.sales_fee_rate', number_format((float) $listing->expected_fee_rate, 2, '.', ''))
                ->assertJsonPath('review_fields', []);
        }
    }

    public function test_platform_kill_switch_blocks_draft_and_listing_write(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $product = $owner->products()->create([
            'internal_sku' => 'FD-KILL-SWITCH-01',
            'product_name' => 'Test item',
            'condition' => 'used_good',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari',
            'name' => 'メルカリ',
            'base_url' => 'https://jp.mercari.com',
            'is_active' => true,
            'default_fee_rate' => 10,
        ]);
        $token = $this->createExtensionDeviceToken($owner);
        config()->set('chrome_extension.platforms.mercari.enabled', false);

        $this->withToken($token)->getJson('/api/extension/v1/products/'.$product->id.'/draft?platform=mercari')
            ->assertStatus(503)
            ->assertJsonPath('message', 'この出品先の拡張機能操作は現在停止中です。');
        $this->withToken($token)->postJson('/api/extension/v1/products/'.$product->id.'/listing-records', [
            'platform' => 'mercari',
            'external_listing_url' => 'https://jp.mercari.com/item/m123456789',
            'listing_price' => 2400,
            'confirmed_listed' => true,
        ])->assertStatus(503);

        $this->assertDatabaseCount('listings', 0, 'furimadeck');
    }

    public function test_extension_image_delivery_is_private_and_scoped_to_owned_products(): void
    {
        Storage::fake(ProductImage::PRIVATE_STORAGE_DISK);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $ownerProduct = $owner->products()->create([
            'internal_sku' => 'FD-IMAGE-OWNER',
            'product_name' => 'Owner item',
            'condition' => 'used_good',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $otherProduct = $other->products()->create([
            'internal_sku' => 'FD-IMAGE-OTHER',
            'product_name' => 'Other item',
            'condition' => 'used_good',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $ownerImage = $ownerProduct->images()->create([
            'original_path' => 'products/owner/photo.webp',
            'derived_path' => 'products/owner/photo-thumb.webp',
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 10,
            'position' => 0,
            'is_primary' => true,
        ]);
        $otherImage = $otherProduct->images()->create([
            'original_path' => 'products/other/photo.webp',
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 10,
            'position' => 0,
            'is_primary' => true,
        ]);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($ownerImage->derived_path, 'owner-image');
        $token = $this->createExtensionDeviceToken($owner);

        $ownerImageResponse = $this->withToken($token)
            ->get('/api/extension/v1/products/'.$ownerProduct->id.'/images/'.$ownerImage->id);
        $ownerImageResponse->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('owner-image', $ownerImageResponse->streamedContent());
        $this->assertStringContainsString('no-store', (string) $ownerImageResponse->headers->get('Cache-Control'));

        $foreignImageResponse = $this->withToken($token)
            ->get('/api/extension/v1/products/'.$otherProduct->id.'/images/'.$otherImage->id);
        $foreignImageResponse->assertNotFound();
    }

    public function test_pairing_code_can_only_be_exchanged_once_and_requires_the_verifier(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $pairing = ExtensionPairing::query()->create([
            'id' => (string) Str::uuid(),
            'code_challenge' => $challenge,
            'redirect_uri' => 'https://'.str_repeat('b', 32).'.chromiumapp.org/pair',
            'device_name' => 'One-time device',
            'approved_user_id' => $owner->id,
            'approval_code_hash' => hash('sha256', str_repeat('c', 64)),
            'expires_at' => now()->addMinutes(5),
            'approved_at' => now(),
        ]);

        $payload = ['pairing_id' => $pairing->id, 'code' => str_repeat('c', 64), 'verifier' => str_repeat('d', 64)];
        $this->postJson('/api/extension/v1/pairing/complete', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('extension_devices', 0, 'furimadeck');

        $payload['verifier'] = $verifier;
        $token = $this->postJson('/api/extension/v1/pairing/complete', $payload)->assertOk()->json('access_token');
        $this->assertIsString($token);
        $this->postJson('/api/extension/v1/pairing/complete', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('extension_devices', 1, 'furimadeck');
    }

    public function test_cors_preflight_allows_chrome_extensions_but_not_web_origins(): void
    {
        $extensionOrigin = 'chrome-extension://'.str_repeat('a', 32);
        $this->withHeader('Origin', $extensionOrigin)
            ->options('/api/extension/v1/pairing/start')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', $extensionOrigin)
            ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');

        $this->withHeader('Origin', 'https://attacker.example')
            ->options('/api/extension/v1/pairing/start')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_configured_extension_identity_is_the_only_production_cors_origin(): void
    {
        $extensionId = str_repeat('a', 32);
        config([
            'app.env' => 'production',
            'chrome_extension.extension_id' => $extensionId,
            'cors.allowed_origins_patterns' => ['~^chrome-extension://'.$extensionId.'$~'],
        ]);
        $trustedOrigin = 'chrome-extension://'.$extensionId;
        $untrustedOrigin = 'chrome-extension://'.str_repeat('b', 32);

        $this->withHeader('Origin', $trustedOrigin)
            ->options('/api/extension/v1/pairing/start')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', $trustedOrigin);

        $this->withHeader('Origin', $untrustedOrigin)
            ->options('/api/extension/v1/pairing/start')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_extension_records_only_confirmed_marketplace_listing_and_reuses_same_url_idempotently(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $product = $owner->products()->create([
            'internal_sku' => 'FD-LIST-01',
            'product_name' => 'Vintage coat',
            'condition' => 'used_good',
            'description_base' => 'A vintage coat',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'mercari',
            'name' => 'メルカリ',
            'base_url' => 'https://jp.mercari.com',
            'is_active' => true,
            'default_fee_rate' => 10,
        ]);
        $token = bin2hex(random_bytes(32));
        ExtensionDevice::query()->create([
            'user_id' => $owner->id,
            'name' => 'Test extension',
            'token_hash' => hash('sha256', $token),
            'scopes' => ExtensionDevice::SCOPES,
            'expires_at' => now()->addDay(),
        ]);
        $payload = [
            'platform' => 'mercari',
            'external_listing_url' => 'https://jp.mercari.com/item/m123456789',
            'listing_price' => 2400,
            'confirmed_listed' => true,
        ];

        $this->withToken($token)->postJson('/api/extension/v1/products/'.$product->id.'/listing-records', $payload)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('listing.status', 'active')
            ->assertJsonPath('listing.record_method', 'manual_confirmed');
        $this->withToken($token)->postJson('/api/extension/v1/products/'.$product->id.'/listing-records', $payload)
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertDatabaseCount('listings', 1, 'furimadeck');
        $this->assertDatabaseHas('listings', [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'external_listing_url' => $payload['external_listing_url'],
            'record_method' => 'manual_confirmed',
            'status' => 'active',
        ], 'furimadeck');
    }

    public function test_extension_listing_record_rejects_unconfirmed_or_foreign_marketplace_urls(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $product = $owner->products()->create([
            'internal_sku' => 'FD-LIST-02',
            'product_name' => 'Vintage bag',
            'condition' => 'used_good',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari',
            'name' => 'メルカリ',
            'base_url' => 'https://jp.mercari.com',
            'is_active' => true,
            'default_fee_rate' => 10,
        ]);
        $token = bin2hex(random_bytes(32));
        ExtensionDevice::query()->create([
            'user_id' => $owner->id,
            'name' => 'Test extension',
            'token_hash' => hash('sha256', $token),
            'scopes' => ExtensionDevice::SCOPES,
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->postJson('/api/extension/v1/products/'.$product->id.'/listing-records', [
            'platform' => 'mercari',
            'external_listing_url' => 'https://jp.mercari.com/item/m123456789',
            'listing_price' => 2400,
            'confirmed_listed' => false,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/extension/v1/products/'.$product->id.'/listing-records', [
            'platform' => 'mercari',
            'external_listing_url' => 'https://jp.mercari.com.example.net/item/m123456789',
            'listing_price' => 2400,
            'confirmed_listed' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('listings', 0, 'furimadeck');
    }

    public function test_extension_message_templates_are_private_validated_and_deletable_by_the_owner(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $ownerToken = $this->createExtensionDeviceToken($owner);
        $otherToken = $this->createExtensionDeviceToken($other);
        $body = '発送まで1〜2日ほどお時間をいただきます。';

        $created = $this->withToken($ownerToken)->postJson('/api/extension/v1/templates', [
            'name' => '発送案内',
            'purpose' => 'shipping',
            'body' => $body,
        ])->assertCreated()->json('data');
        $this->withToken($ownerToken)->getJson('/api/extension/v1/templates')
            ->assertOk()->assertJsonPath('data.0.body', $body);
        $this->withToken($ownerToken)->patchJson('/api/extension/v1/templates/'.$created['id'], [
            'name' => '発送予定',
            'purpose' => 'shipping',
            'body' => '発送予定を更新しました。',
        ])->assertOk()->assertJsonPath('data.name', '発送予定');
        $this->withToken($otherToken)->getJson('/api/extension/v1/templates')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($otherToken)->deleteJson('/api/extension/v1/templates/'.$created['id'])
            ->assertNotFound();
        $this->withToken($ownerToken)->deleteJson('/api/extension/v1/templates/'.$created['id'])
            ->assertOk()->assertJsonPath('deleted', true);

        $this->assertDatabaseCount('extension_message_templates', 0, 'furimadeck');
    }

    public function test_extension_activity_returns_only_safe_extension_events_for_the_connected_user(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $ownerToken = $this->createExtensionDeviceToken($owner);
        $otherToken = $this->createExtensionDeviceToken($other);
        $limitedToken = bin2hex(random_bytes(32));
        ExtensionDevice::query()->create([
            'user_id' => $owner->id,
            'name' => 'Limited extension',
            'token_hash' => hash('sha256', $limitedToken),
            'scopes' => ['products:read'],
            'expires_at' => now()->addDay(),
        ]);
        $auditLogger = app(AuditLogger::class);
        $auditLogger->log($owner, ExtensionMessageTemplate::class, 1, 'chrome_extension.template_created', null, [
            'name' => 'Private template name',
            'body' => 'Private template text',
        ]);
        $auditLogger->log($other, ExtensionMessageTemplate::class, 2, 'chrome_extension.template_created');
        $auditLogger->log($owner, ExtensionMessageTemplate::class, 3, 'product.updated');

        $this->withToken($ownerToken)->getJson('/api/extension/v1/activity')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'chrome_extension.template_created')
            ->assertJsonStructure(['data' => [['action', 'occurred_at']]])
            ->assertJsonMissing(['Private template name'])
            ->assertJsonMissing(['Private template text']);
        $this->withToken($otherToken)->getJson('/api/extension/v1/activity')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->withToken($limitedToken)->getJson('/api/extension/v1/activity')->assertUnauthorized();
    }

    public function test_extension_csv_exports_only_the_connected_users_products_listings_and_sales(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $ownerProduct = $owner->products()->create([
            'internal_sku' => 'FD-CSV-OWNER',
            'product_name' => '=HYPERLINK("https://example.test")',
            'condition' => 'used_good',
            'description_base' => 'Owner description',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $other->products()->create([
            'internal_sku' => 'FD-CSV-OTHER',
            'product_name' => 'Other user product',
            'condition' => 'used_good',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'mercari',
            'name' => 'メルカリ',
            'base_url' => 'https://jp.mercari.com',
            'is_active' => true,
            'default_fee_rate' => 10,
        ]);
        $listing = $owner->listings()->create([
            'product_id' => $ownerProduct->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => 'Owner listing',
            'listing_price' => 2400,
            'external_listing_url' => 'https://jp.mercari.com/item/m123456789',
            'record_method' => 'manual_confirmed',
            'status' => 'active',
            'listed_at' => now(),
        ]);
        $owner->sales()->create([
            'product_id' => $ownerProduct->id,
            'listing_id' => $listing->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => 'FD-CSV-OWNER',
            'product_name_snapshot' => 'Owner sale snapshot',
            'quantity' => 1,
            'sold_price' => 2400,
            'sold_at' => now(),
            'status' => 'completed',
            'sales_fee' => 240,
            'shipping_fee' => 210,
            'net_profit' => 1950,
        ]);
        $other->sales()->create([
            'product_id' => $other->products()->firstOrFail()->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => 'FD-CSV-OTHER',
            'product_name_snapshot' => 'Other sale snapshot',
            'quantity' => 1,
            'sold_price' => 900,
            'sold_at' => now(),
            'status' => 'completed',
        ]);
        $token = $this->createExtensionDeviceToken($owner);

        $productsCsv = $this->withToken($token)->get('/api/extension/v1/exports/products.csv')
            ->assertOk()
            ->assertDownload('furimadeck-products.csv')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $productsCsv);
        $this->assertStringContainsString("'=HYPERLINK", $productsCsv);
        $this->assertStringContainsString('purchase_quantity', $productsCsv);
        $this->assertStringNotContainsString('created_at', $productsCsv);
        $this->assertStringNotContainsString('FD-CSV-OTHER', $productsCsv);
        $productImportPreview = app(ProductCsvPreviewService::class)->preview(
            UploadedFile::fake()->createWithContent('extension-products.csv', $productsCsv),
        );
        $this->assertCount(1, $productImportPreview['rows']);
        $this->assertSame([], $productImportPreview['rows'][0]['errors']);

        $listingsCsv = $this->withToken($token)->get('/api/extension/v1/exports/listings.csv')
            ->assertDownload('furimadeck-listings.csv')
            ->streamedContent();
        $this->assertStringContainsString('external_listing_url', $listingsCsv);
        $this->assertStringContainsString('manual_confirmed', $listingsCsv);
        $this->assertStringNotContainsString('FD-CSV-OTHER', $listingsCsv);

        $salesCsv = $this->withToken($token)->get('/api/extension/v1/exports/sales.csv')
            ->assertDownload('furimadeck-sales.csv')
            ->streamedContent();
        $this->assertStringContainsString('sold_at', $salesCsv);
        $this->assertStringContainsString('Owner sale snapshot', $salesCsv);
        $this->assertStringNotContainsString('Other sale snapshot', $salesCsv);
    }

    private function createExtensionDeviceToken(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        ExtensionDevice::query()->create([
            'user_id' => $user->id,
            'name' => 'Test extension',
            'token_hash' => hash('sha256', $token),
            'scopes' => ExtensionDevice::SCOPES,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
