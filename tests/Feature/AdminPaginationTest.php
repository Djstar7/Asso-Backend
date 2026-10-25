<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

class AdminPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        $this->app->instance('request', Request::create('/admin/products'));
    }

    private function pages(int $page = 1, int $total = 1000): LengthAwarePaginator
    {
        $count = max(0, min(10, $total - ($page - 1) * 10));

        return new LengthAwarePaginator(array_fill(0, $count, 'product'), $total, 10, $page, [
            'path' => '/admin/products',
        ]);
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_first_page_disables_previous_and_describes_results(): void
    {
        $html = $this->pages()->links()->toHtml();
        $xpath = $this->dom($html);

        $this->assertStringContainsString('Affichage 1–10 sur 1000 résultats', $html);
        $this->assertSame(0, $xpath->query('//a[@rel="prev"]')->length);
        $this->assertSame(1, $xpath->query('//a[@rel="next"]')->length);
        $this->assertSame('1', trim($xpath->query('//*[@aria-current="page"]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//*[@aria-disabled="true"]')->length);
    }

    public function test_middle_page_has_a_compact_window_with_both_directions(): void
    {
        $html = $this->pages(50)->links()->toHtml();
        $xpath = $this->dom($html);

        $this->assertStringContainsString('Affichage 491–500 sur 1000 résultats', $html);
        $this->assertStringContainsString('Page 50 sur 100', $html);
        $this->assertSame(2, $xpath->query('//a[@rel="prev" or @rel="next"]')->length);
        $this->assertLessThanOrEqual(8, $xpath->query('//a')->length);
        $this->assertSame('50', trim($xpath->query('//*[@aria-current="page"]')->item(0)->textContent));
        $this->assertStringContainsString('…', $html);
    }

    public function test_last_page_disables_next(): void
    {
        $html = $this->pages(100)->links()->toHtml();
        $xpath = $this->dom($html);

        $this->assertStringContainsString('Affichage 991–1000 sur 1000 résultats', $html);
        $this->assertSame(0, $xpath->query('//a[@rel="next"]')->length);
        $this->assertSame(1, $xpath->query('//a[@rel="prev"]')->length);
        $this->assertSame(1, $xpath->query('//*[@aria-disabled="true"]')->length);
    }

    public function test_empty_and_single_page_states_have_no_fake_navigation(): void
    {
        $empty = $this->pages(1, 0)->links()->toHtml();
        $single = $this->pages(1, 4)->links()->toHtml();

        $this->assertStringContainsString('Aucun résultat', $empty);
        $this->assertSame(0, $this->dom($empty)->query('//a')->length);
        $this->assertStringContainsString('Affichage 1–4 sur 4 résultats', $single);
        $this->assertSame(0, $this->dom($single)->query('//a')->length);
    }

    public function test_all_links_keep_filters_custom_page_parameter_and_fragment(): void
    {
        $filters = ['type' => 'boost', 'search' => 'robe été', 'status' => 'active', 'filters' => ['country' => ['CM', 'FR']], 'other_page' => 3, 'products_page' => 4];
        $this->app->instance('request', Request::create('/admin/packages', 'GET', $filters));
        $paginator = new LengthAwarePaginator(array_fill(0, 10, 'product'), 1000, 10, 4, [
            'path' => '/admin/packages', 'pageName' => 'products_page', 'fragment' => 'results',
        ]);
        $links = $this->dom($paginator->links()->toHtml())->query('//a');
        $this->assertGreaterThan(0, $links->length);

        foreach ($links as $link) {
            $url = parse_url($link->getAttribute('href'));
            parse_str($url['query'], $query);
            $this->assertSame('results', $url['fragment']);
            foreach (array_diff_key($filters, ['products_page' => true]) as $key => $value) {
                $this->assertEquals($value, $query[$key]);
            }
            $this->assertArrayHasKey('products_page', $query);
            $this->assertNotSame('4', $query['products_page']);
            $this->assertArrayNotHasKey('page', $query);
        }
    }

    public function test_simple_paginator_handles_first_and_last_pages_without_a_total(): void
    {
        $first = new Paginator(range(1, 11), 10, 1, ['path' => '/admin/products']);
        $firstHtml = $first->links()->toHtml();
        $this->assertStringContainsString('Page 1', $firstHtml);
        $this->assertSame(1, $this->dom($firstHtml)->query('//a[@rel="next"]')->length);
        $this->assertSame(0, $this->dom($firstHtml)->query('//a[@rel="prev"]')->length);

        $last = new Paginator(range(1, 3), 10, 2, ['path' => '/admin/products']);
        $lastHtml = $last->links()->toHtml();
        $this->assertSame(0, $this->dom($lastHtml)->query('//a[@rel="next"]')->length);
        $this->assertSame(1, $this->dom($lastHtml)->query('//a[@rel="prev"]')->length);
    }

    public function test_anonymous_pagination_uses_application_locale(): void
    {
        app()->setLocale('en');
        $html = $this->pages()->links()->toHtml();
        $this->assertStringContainsString('Showing 1–10 of 1000 results', $html);
        $this->assertStringContainsString('lang="en"', $html);
    }

    public function test_signed_in_account_locale_takes_precedence_over_application_locale(): void
    {
        $user = new User;
        $user->locale = 'en';
        $this->actingAs($user);
        $html = $this->pages()->links()->toHtml();
        $this->assertStringContainsString('Showing 1–10 of 1000 results', $html);
        $this->assertStringContainsString('Next', $html);
        $this->assertStringNotContainsString('Affichage', $html);
    }
}
