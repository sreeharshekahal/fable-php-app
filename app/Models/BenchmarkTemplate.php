<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BenchmarkTemplate extends Model
{
    public const PERIODS = ['BOY', 'MOY', 'EOY'];

    protected $table = 'benchmark_templates';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'organisation_ids',
        'language_ids',
        'assessment_period',
    ];

    protected $casts = [
        'organisation_ids'          => 'array',
        'language_ids'              => 'array',
    ];

    /**
     * Auto-generate a UUID on creation if none is provided.
     */
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * A BenchmarkTemplate has many GroupingParameters (the actual benchmarks).
     */
    public function groupingParameters()
    {
        return $this->hasMany(GroupingParameter::class, 'benchmark_template_id');
    }

    /**
     * A BenchmarkTemplate has many Accuracies.
     */
    public function accuracies()
    {
        return $this->hasMany(Accuracy::class, 'benchmark_template_id');
    }

    /**
     * Scope: template for a specific organisation and language pair.
     */
    public function scopeForOrganisationLanguage($query, string $organisationId, string $languageId)
    {
        return $query->whereJsonContains('organisation_ids', $organisationId)
            ->whereJsonContains('language_ids', $languageId);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------


    /**
     * Scope: templates for a specific organisation.
     */
    public function scopeForOrganisation($query, string $organisationId)
    {
        return $query->whereJsonContains('organisation_ids', $organisationId);
    }

    /**
     * Helper to find template ID for an organisation and language and optionally period.
     */
    public static function findTemplateId(string $organisationId, string $languageId): ?string
    {
        return self::forOrganisationLanguage($organisationId, $languageId)->value('id');
    }

    /**
     * Helper to find a template model for an organisation and language pair.
     */
    public static function findForOrganisationLanguage(string $organisationId, string $languageId): ?self
    {
        return self::forOrganisationLanguage($organisationId, $languageId)->first();
    }

    public function activeAssessmentPeriodForLanguage(string $languageId): ?string
    {
        // First check if any grouping parameters for this template and language have a non-null period
        $period = $this->groupingParameters()
            ->where('language_id', $languageId)
            ->whereNotNull('assessment_period')
            ->value('assessment_period');

        if ($period && in_array($period, self::PERIODS, true)) {
            return $period;
        }

        if (in_array($this->assessment_period, self::PERIODS, true)) {
            return $this->assessment_period;
        }

        return null;
    }

    public function normalizedActiveAssessmentPeriods(): array
    {
        $languageIds = $this->language_ids ?? [];
        $normalized = [];

        if (empty($languageIds)) {
            return $normalized;
        }

        // Single query to get all periods for this template grouped by language
        $periods = $this->groupingParameters()
            ->whereIn('language_id', $languageIds)
            ->whereNotNull('assessment_period')
            ->pluck('assessment_period', 'language_id');

        foreach ($languageIds as $languageId) {
            $period = $periods[$languageId] ?? null;

            if ($period && in_array($period, self::PERIODS, true)) {
                $normalized[$languageId] = $period;
            } elseif (in_array($this->assessment_period, self::PERIODS, true)) {
                $normalized[$languageId] = $this->assessment_period;
            } else {
                $normalized[$languageId] = null;
            }
        }

        return $normalized;
    }
}
