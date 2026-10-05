<?php
// bot/FAQHandler.php
if (!class_exists('FAQHandler')):
class FAQHandler {
    private Database $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function findAnswer(string $question): ?string {
        $faqs = $this->db->fetchAll("SELECT * FROM faqs WHERE is_active=1");
        if (empty($faqs)) return null;

        $q     = mb_strtolower($question,'UTF-8');
        $words = array_filter(explode(' ', $q), fn($w)=>mb_strlen($w)>2);
        $best  = null; $top = 0;

        foreach ($faqs as $faq) {
            $score = 0;
            foreach (array_filter(explode(',', mb_strtolower($faq['keywords']??'','UTF-8'))) as $kw) {
                if (mb_strpos($q, trim($kw)) !== false) $score += 10;
            }
            foreach (array_filter(explode(' ', mb_strtolower($faq['question'],'UTF-8'))) as $fw) {
                if (mb_strlen($fw)>2 && in_array($fw,$words)) $score += 3;
            }
            if ($score > $top) { $top=$score; $best=$faq; }
        }
        return ($top>=5 && $best) ? $best['answer'] : null;
    }
}
endif;
