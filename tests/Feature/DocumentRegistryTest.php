<?php

namespace Tests\Feature;

use App\Services\ContentRegistry;
use App\Services\DocumentSchema;
use App\Services\SafeUrl;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentRegistryTest extends TestCase
{
    public static function layouts(): array
    {
        $registry = json_decode(file_get_contents(__DIR__.'/../../resources/content/registry.json'), true);
        return array_column(array_map(fn ($layout) => [$layout['id'], [$layout]], $registry['layouts']), 1, 0);
    }

    public static function blocks(): array
    {
        $registry = json_decode(file_get_contents(__DIR__.'/../../resources/content/registry.json'), true);
        return array_column(array_map(fn ($block) => [$block['id'], [$block]], $registry['blocks']), 1, 0);
    }

    private function document(string $layout = 'stack', array $blocks = []): array
    {
        return ['sections' => [['id' => 'section-one', 'layout' => $layout, 'settings' => [], 'blocks' => $blocks]]];
    }

    #[DataProvider('layouts')]
    public function test_each_layout_accepts_empty_and_maximum_content_and_rejects_overflow(array $layout): void
    {
        $schema = app(DocumentSchema::class);
        $this->assertSame([], $schema->validate($this->document($layout['id']))['sections'][0]['blocks']);
        $type = $layout['allowedBlocks'][0] ?? 'text';
        $defaults = app(ContentRegistry::class)->block($type)['defaults'];
        $blocks = array_map(fn ($i) => ['id' => 'block-'.$i, 'type' => $type, 'props' => $defaults], range(1, $layout['maxBlocks']));
        $this->assertCount($layout['maxBlocks'], $schema->validate($this->document($layout['id'], $blocks))['sections'][0]['blocks']);
        $blocks[] = ['id' => 'overflow', 'type' => $type, 'props' => $defaults];
        $this->expectException(ValidationException::class);
        $schema->validate($this->document($layout['id'], $blocks));
    }

    #[DataProvider('blocks')]
    public function test_each_registered_block_validates_defaults_and_rejects_undeclared_properties(array $block): void
    {
        $schema = app(DocumentSchema::class);
        $document = $this->document('stack', [['id' => 'block-one', 'type' => $block['id'], 'props' => $block['defaults']]]);
        $this->assertSame($block['defaults'], $schema->validate($document)['sections'][0]['blocks'][0]['props']);
        $document['sections'][0]['blocks'][0]['props']['onClick'] = 'alert(1)';
        $this->expectException(ValidationException::class);
        $schema->validate($document);
    }

    public function test_restricted_layout_rejects_incompatible_content(): void
    {
        $this->expectException(ValidationException::class);
        app(DocumentSchema::class)->validate($this->document('tabs', [['id' => 'block-one', 'type' => 'heading', 'props' => ['text' => 'Bad placement', 'level' => 1]]]));
    }

    public function test_plugin_registered_block_uses_its_server_validator_and_can_be_removed(): void
    {
        $registry = app(ContentRegistry::class);
        $registry->registerBlock('example/demo/badge', ['fields' => [['key' => 'text', 'type' => 'text', 'required' => true]], 'defaults' => []], function (array $props) {
            if ($props['text'] !== 'approved') throw ValidationException::withMessages(['document' => 'Badge content is not approved.']);
            return $props;
        });
        $registry->registerLayout('example/demo/cards', ['maxBlocks' => 4, 'allowedBlocks' => ['example/demo/badge']]);
        $document = $this->document('example/demo/cards', [['id' => 'block-one', 'type' => 'example/demo/badge', 'props' => ['text' => 'approved']]]);
        $this->assertSame('approved', app(DocumentSchema::class)->validate($document)['sections'][0]['blocks'][0]['props']['text']);
        $document['sections'][0]['blocks'][0]['props']['text'] = 'not approved';
        try {
            app(DocumentSchema::class)->validate($document);
            $this->fail('The custom server validator was bypassed.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('not approved', $error->getMessage());
        }
        $registry->forgetPlugin('example/demo');
        $this->assertNull($registry->block('example/demo/badge'));
        $this->assertNull($registry->layout('example/demo/cards'));
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], ['javascript:alert(1)', '//evil.test', '/\\evil.test', '/%5cevil.test', '/%2fevil.test', "https://good.test\n@evil.test", 'https://user:password@example.test', 'data:text/html,test', 'ftp://example.test/file']);
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_url_forms_are_rejected(string $url): void
    {
        $this->assertFalse(SafeUrl::valid($url));
        $this->expectException(ValidationException::class);
        app(DocumentSchema::class)->validate($this->document('stack', [['id' => 'block-one', 'type' => 'button', 'props' => ['text' => 'Go', 'url' => $url]]]));
    }

    public static function invalidFields(): array
    {
        return [
            'number out of range' => ['spacer', ['height' => 5000]],
            'number wrong type' => ['spacer', ['height' => '32']],
            'select invalid option' => ['heading', ['text' => 'Title', 'level' => 9]],
            'text wrong type' => ['text', ['text' => false]],
            'boolean wrong type' => ['list', ['ordered' => 'false']],
            'unsafe image collection' => ['gallery', ['items' => 'javascript:bad|Caption']],
            'unsafe card collection' => ['card-collection', ['items' => 'Title|Body|javascript:bad']],
            'untrusted video host' => ['video', ['url' => 'https://evil.test/watch?v=abcdefghijk']],
            'array video query' => ['video', ['url' => 'https://youtube.com/watch?v[]=abcdefghijk']],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_semantically_invalid_fields_are_rejected(string $type, array $props): void
    {
        $this->expectException(ValidationException::class);
        app(DocumentSchema::class)->validate($this->document('stack', [['id' => 'block-one', 'type' => $type, 'props' => $props]]));
    }
}
