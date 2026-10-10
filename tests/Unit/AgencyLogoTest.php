<?php

namespace Avocadesign\StatamicTools\Tests\Unit;

use Avocadesign\StatamicTools\Site\AgencyLogo;
use Avocadesign\StatamicTools\Tests\TestCase;

class AgencyLogoTest extends TestCase
{
    private const KIT_HEADER = '<img src="/agencies/{{ config:agency:handle }}/logo.svg" class="select-none {{ class }}" alt="">';

    public function test_the_kit_as_installed_shows_the_agency_s_logo_in_both_places(): void
    {
        $problems = AgencyLogo::problems(['en' => 'https://example.test/agencies/avoca/logo.png'], self::KIT_HEADER);

        $this->assertCount(2, $problems);
        $this->assertStringStartsWith('form emails still carry the agency\'s logo (en)', $problems[0]);
        $this->assertStringStartsWith('the site header still shows the agency\'s logo', $problems[1]);
    }

    public function test_the_client_s_logo_in_both_places_is_no_problem(): void
    {
        $header = '<svg viewBox="0 0 120 40" role="img" aria-label="Client"><path d="M0 0h120v40H0z"/></svg>';

        $this->assertSame([], AgencyLogo::problems(['en' => 'https://example.test/visuals/client-logo.png'], $header));
    }

    public function test_every_language_still_on_the_agency_s_logo_is_named_in_one_line(): void
    {
        $problems = AgencyLogo::problems([
            'en' => 'https://example.test/visuals/client-logo.png',
            'mi' => 'https://example.test/agencies/avoca/logo.png',
            'de' => 'https://example.test/agencies/avoca/logo.png',
        ], null);

        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('form emails still carry the agency\'s logo (mi, de)', $problems[0]);
    }

    public function test_a_site_without_the_partial_or_the_string_says_nothing_about_it(): void
    {
        // trans() hands back the key when a language has no form_mail_logo, which names no agency.
        $this->assertSame([], AgencyLogo::problems(['en' => 'strings.form_mail_logo'], null));
    }
}
