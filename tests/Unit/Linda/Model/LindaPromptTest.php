<?php

namespace Tests\Unit\Linda\Model;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\Model\LindaPrompt;
use App\Services\Linda\Model\LindaUnderstanding;
use App\Services\Linda\Model\LindaUsage;
use Tests\Unit\Linda\LindaTestCase;

/**
 * The model prompt reads Bisaya/Cebuano beside English and Filipino, and
 * stays date-free and cacheable.
 */
class LindaPromptTest extends LindaTestCase
{
    public function test_the_prompt_names_the_three_languages_and_answers_stay_classification_only(): void
    {
        $prompt = LindaPrompt::system();

        $this->assertStringContainsString('English, Filipino/Taglish or Bisaya/Cebuano', $prompt);
        $this->assertDoesNotMatchRegularExpression('/\b(19|20)\d{2}\b/', $prompt);
        $this->assertGreaterThan(4 * 512 * 1.5, strlen($prompt));
    }

    public function test_the_prompt_translates_the_bisaya_periods_and_follow_ups(): void
    {
        $prompt = LindaPrompt::system();

        foreach (['"gahapon" is "yesterday"', '"karong bulana"', '"niaging bulan"', '"niaging semana"', '"karong tuiga"', '"gikan sa"', '"hangtod sa"', '"unya, niaging bulan?"', '"kumusta sa Main?"'] as $phrase) {
            $this->assertStringContainsString($phrase, $prompt);
        }
    }

    public function test_every_intent_line_has_a_bisaya_example(): void
    {
        $prompt = LindaPrompt::system();

        foreach (['Pila ang nakolekta', 'Pila ka active loans', 'Kinsa ang naay atraso', 'Pila ang na-release', 'Unsang branch', 'Kinsa nga officer', 'porsyento sa koleksyon', 'kinatibuk-an'] as $example) {
            $this->assertStringContainsString($example, $prompt);
        }
    }

    /**
     * Words a model may leave around a Bisaya or Tagalog period still read as
     * that period.
     */
    public function test_bisaya_fillers_around_model_period_words(): void
    {
        $this->atManila('2026-11-08 12:00');
        $usage = new LindaUsage('claude-haiku-5-5', 1, 1);

        $understanding = LindaUnderstanding::fromAnswer(LindaIntent::CollectionSummary, 'niadtong sa Oktubre', null, false, $usage);

        $this->assertFalse($understanding->periodUnclear);
        $this->assertInstanceOf(LindaPeriod::class, $understanding->period);
        $this->assertSame('2026-10-01', $understanding->period->from->toDateString());
    }
}
