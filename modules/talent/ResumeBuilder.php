<?php

declare(strict_types=1);

namespace Blue\Modules\Talent;

/**
 * Resume builder input → a clean resume to print on A4 (phase 5E, DEC-76). Nothing is stored: the page is rebuilt from
 * the form each time, and the person prints it or saves it as PDF from the browser.
 */
final class ResumeBuilder
{
    public const WORK_ROWS = 5;
    public const EDU_ROWS = 3;

    /**
     * @param array<string, mixed> $in
     * @return array{name: string, headline: string, email: string, phone: string, location: string, summary: string, skills: list<string>, languages: string,
     *               work: list<array{title: string, org: string, from: string, to: string, desc: string}>, education: list<array{school: string, degree: string, year: string}>}
     */
    public static function clean(array $in): array
    {
        $s = static fn (mixed $v, int $max): string => is_scalar($v) ? mb_substr(trim(str_replace("\r", '', (string) $v)), 0, $max) : '';
        $work = [];
        for ($i = 0; $i < self::WORK_ROWS; $i++) {
            $row = ['title' => $s($in['work_title_' . $i] ?? '', 120), 'org' => $s($in['work_org_' . $i] ?? '', 120), 'from' => $s($in['work_from_' . $i] ?? '', 20),
                'to' => $s($in['work_to_' . $i] ?? '', 20), 'desc' => $s($in['work_desc_' . $i] ?? '', 800)];
            if ($row['title'] !== '' || $row['org'] !== '') {
                $work[] = $row;
            }
        }
        $edu = [];
        for ($i = 0; $i < self::EDU_ROWS; $i++) {
            $row = ['school' => $s($in['edu_school_' . $i] ?? '', 160), 'degree' => $s($in['edu_degree_' . $i] ?? '', 160), 'year' => $s($in['edu_year_' . $i] ?? '', 20)];
            if ($row['school'] !== '' || $row['degree'] !== '') {
                $edu[] = $row;
            }
        }
        $skills = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/u', $s($in['skills'] ?? '', 1000)) ?: []), static fn (string $x): bool => $x !== ''));
        return ['name' => $s($in['name'] ?? '', 120), 'headline' => $s($in['headline'] ?? '', 160), 'email' => $s($in['email'] ?? '', 160), 'phone' => $s($in['phone'] ?? '', 30),
            'location' => $s($in['location'] ?? '', 120), 'summary' => $s($in['summary'] ?? '', 1500), 'skills' => array_slice($skills, 0, 30), 'languages' => $s($in['languages'] ?? '', 300),
            'work' => $work, 'education' => $edu];
    }
}
