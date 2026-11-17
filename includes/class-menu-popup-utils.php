<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Utils {

    /**
     * Process video URL - convert watch URLs to embed URLs for YouTube/Vimeo/TikTok
     */
    public function process_video_url($url) {
        if (empty($url)) {
            return $url;
        }

        $url = trim($url);

        // Handle YouTube URLs (including mobile)
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false || strpos($url, 'm.youtube.com') !== false) {
            return $this->process_youtube_url($url);
        }

        // Handle Vimeo URLs
        if (strpos($url, 'vimeo.com') !== false) {
            return $this->process_vimeo_url($url);
        }

        // Handle TikTok URLs
        if (strpos($url, 'tiktok.com') !== false) {
            return $this->process_tiktok_url($url);
        }

        // Return original URL if it doesn't match any pattern
        return $url;
    }

    /**
     * Process YouTube URLs specifically
     */
    public function process_youtube_url($url) {
        // Handle mobile YouTube URLs (m.youtube.com)
        if (strpos($url, 'm.youtube.com') !== false) {
            $video_id = $this->extract_video_id($url);
            if ($video_id) {
                return 'https://www.youtube.com/embed/' . $video_id;
            }
        }

        // YouTube watch URL with various parameters
        if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        // YouTube watch URL (clean)
        if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        // YouTube short URL (youtu.be)
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        // YouTube embed URL with parameters
        if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        // YouTube live stream or other formats
        if (preg_match('/youtube\.com\/.*\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        // If it's already a valid embed URL, return as-is
        if (strpos($url, 'youtube.com/embed/') !== false) {
            return $url;
        }

        // Try to extract video ID using a more general approach
        $video_id = $this->extract_video_id($url);
        if ($video_id) {
            return 'https://www.youtube.com/embed/' . $video_id;
        }

        // If we can't parse it, return the original URL
        return $url;
    }

    /**
     * Extract video ID from various YouTube URL formats
     */
    public function extract_video_id($url) {
        // Try multiple patterns to extract video ID

        // Pattern 1: watch?v=VIDEO_ID
        if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        // Pattern 2: youtu.be/VIDEO_ID
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        // Pattern 3: embed/VIDEO_ID
        if (preg_match('/embed\/([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        // Pattern 4: /v/VIDEO_ID (old format)
        if (preg_match('/\/v\/([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        return false;
    }

    /**
     * Process Vimeo URLs specifically
     */
    public function process_vimeo_url($url) {
        // Vimeo video URL
        if (preg_match('/vimeo\.com\/(\d+)/', $url, $matches)) {
            return 'https://player.vimeo.com/video/' . $matches[1];
        }

        // Vimeo embed URL (already correct)
        if (preg_match('/player\.vimeo\.com\/video\/(\d+)/', $url)) {
            return $url;
        }

        // Return original if we can't parse it
        return $url;
    }

    /**
     * Process TikTok URLs
     */
    public function process_tiktok_url($url) {
        // TikTok video URL
        if (preg_match('/tiktok\.com\/@(?:[a-zA-Z0-9._-]+)\/video\/(\d+)/', $url, $matches)) {
            // TikTok embed is usually done via a script or iframe with a data-video-id
            // For simplicity, we'll return a generic embed URL, actual embedding often requires JS
            return 'https://www.tiktok.com/embed/v2/' . $matches[1];
        }
        // Return original if we can't parse it or it's not a direct video URL
        return $url;
    }

    /**
     * Normalize Arabic text for better search matching
     * Removes diacritics, normalizes character forms, and handles Unicode variations
     */
    public function normalize_arabic_text($text) {
        if (empty($text)) {
            return $text;
        }

        // Convert to UTF-8 if not already
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remove Arabic diacritics (Tashkeel)
        $diacritics = [
            'َ', 'ً', 'ُ', 'ٌ', 'ِ', 'ٍ', 'ْ', 'ّ', // Harakat
            'ٔ', 'ٕ', 'ٓ', 'ٛ', 'ٚ', 'ٙ', '٘', 'ٗ', 'ٖ', 'ٕ', // Other diacritics
            'ـ', // Tatweel (elongation)
        ];

        foreach ($diacritics as $diacritic) {
            $text = str_replace($diacritic, '', $text);
        }

        // Normalize different forms of the same letters
        $normalizations = [
            // Alif variations
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            // Hamza variations
            'ؤ' => 'و', 'ئ' => 'ي', 'ء' => '',
            // Teh Marbuta
            'ة' => 'ه',
            // Different Yeh forms
            'ى' => 'ي',
            // Different Waw forms
            'ٶ' => 'و', 'ٷ' => 'و', 'ٸ' => 'و',
            // Different Kaf forms
            'ك' => 'ك', 'ک' => 'ك', 'ګ' => 'ك',
            // Different Heh forms
            'ه' => 'ه', 'ۀ' => 'ه', 'ە' => 'ه',
            // Different Ain forms
            'ع' => 'ع', 'ٴ' => 'ع',
            // Different Sad forms
            'ص' => 'ص', 'ڝ' => 'ص',
            // Different Dad forms
            'ض' => 'ض', 'ڞ' => 'ض',
            // Different Tah forms
            'ط' => 'ط', 'ٺ' => 'ط',
            // Different Zah forms
            'ظ' => 'ظ', 'ڟ' => 'ظ',
        ];

        foreach ($normalizations as $from => $to) {
            $text = str_replace($from, $to, $text);
        }

        // Remove extra spaces and normalize
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Enhanced Arabic search with optimized performance
     */
    public function search_arabic_items($search_query, $allowed_categories = array()) {
        $normalized_query = $this->normalize_arabic_text($search_query);
        $query_lower = strtolower($search_query);

        // Limit results for performance - only get top matches
        $max_results = 50;

        // Get posts with menu items - use more efficient query
        $args = array(
            'post_type' => array('post', 'menu_item'),
            'post_status' => 'publish',
            'posts_per_page' => $max_results * 2, // Get more than needed for filtering
            'meta_query' => array(
                'relation' => 'OR',
                // Search in Arabic name field
                array(
                    'key' => '_menu_item_name_arabic',
                    'value' => $normalized_query,
                    'compare' => 'LIKE'
                ),
                // Search in Arabic description field
                array(
                    'key' => '_menu_item_description_arabic',
                    'value' => $normalized_query,
                    'compare' => 'LIKE'
                ),
                // Also include English fields for fallback
                array(
                    'key' => '_menu_item_name',
                    'value' => $query_lower,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_menu_item_description',
                    'value' => $query_lower,
                    'compare' => 'LIKE'
                )
            )
        );

        // Apply category filters if specified
        if (!empty($allowed_categories)) {
            $args['meta_query'][] = array(
                'key' => '_menu_item_category',
                'value' => $allowed_categories,
                'compare' => 'IN'
            );
        }

        $query = new WP_Query($args);
        $matches = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Get both Arabic and English content in single batch if possible
                $arabic_name = get_post_meta($post_id, '_menu_item_name_arabic', true);
                $arabic_desc = get_post_meta($post_id, '_menu_item_description_arabic', true);
                $english_name = get_post_meta($post_id, '_menu_item_name', true);
                $english_desc = get_post_meta($post_id, '_menu_item_description', true);

                $score = 0;
                $matched_field = '';

                // Check Arabic name match (exact substring)
                if (!empty($arabic_name)) {
                    $normalized_arabic_name = $this->normalize_arabic_text($arabic_name);
                    if (strpos($normalized_arabic_name, $normalized_query) !== false) {
                        $score = 100; // Exact substring match
                        $matched_field = 'arabic_name_exact';
                    } elseif (strpos($arabic_name, $search_query) !== false) {
                        $score = 95; // Direct match without normalization
                        $matched_field = 'arabic_name_direct';
                    }
                }

                // Check Arabic description match
                if ($score === 0 && !empty($arabic_desc)) {
                    $normalized_arabic_desc = $this->normalize_arabic_text($arabic_desc);
                    if (strpos($normalized_arabic_desc, $normalized_query) !== false) {
                        $score = 90; // Description match
                        $matched_field = 'arabic_desc_exact';
                    } elseif (strpos($arabic_desc, $search_query) !== false) {
                        $score = 85; // Direct match without normalization
                        $matched_field = 'arabic_desc_direct';
                    }
                }

                // Fallback to English search if no Arabic match
                if ($score === 0) {
                    if (!empty($english_name) && strpos(strtolower($english_name), $query_lower) !== false) {
                        $score = 60; // English name match
                        $matched_field = 'english_name';
                    } elseif (!empty($english_desc) && strpos(strtolower($english_desc), $query_lower) !== false) {
                        $score = 50; // English description match
                        $matched_field = 'english_desc';
                    }
                }

                // If we have a match, add it to results
                if ($score > 0) {
                    $matches[] = array(
                        'post' => get_post(),
                        'score' => $score,
                        'matched_field' => $matched_field,
                        'arabic_name' => $arabic_name,
                        'english_name' => $english_name
                    );
                }

                // Early exit if we have enough results
                if (count($matches) >= $max_results) {
                    break;
                }
            }
        }

        wp_reset_postdata();

        // Sort by score (highest first) and limit results
        usort($matches, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        return array_slice($matches, 0, $max_results);
    }

    /**
     * Calculate similarity between two Arabic strings
     */
    public function calculate_arabic_similarity($str1, $str2) {
        if (empty($str1) || empty($str2)) {
            return 0;
        }

        // Exact match gets highest score
        if ($str1 === $str2) {
            return 1.0;
        }

        // Substring match
        if (strpos($str2, $str1) !== false || strpos($str1, $str2) !== false) {
            return 0.9;
        }

        // Calculate Jaccard similarity for word overlap
        $words1 = array_unique(explode(' ', $str1));
        $words2 = array_unique(explode(' ', $str2));

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        if (empty($union)) {
            return 0;
        }

        $jaccard = count($intersection) / count($union);

        // Boost score if we have significant word overlap
        if ($jaccard > 0.5) {
            return min(0.8, $jaccard + 0.2);
        }

        // Levenshtein distance for small strings
        if (mb_strlen($str1) <= 20 && mb_strlen($str2) <= 20) {
            $distance = $this->levenshtein_arabic($str1, $str2);
            $max_len = max(mb_strlen($str1), mb_strlen($str2));
            if ($max_len > 0) {
                $similarity = 1 - ($distance / $max_len);
                if ($similarity > 0.7) {
                    return $similarity;
                }
            }
        }

        return $jaccard > 0 ? $jaccard * 0.5 : 0;
    }

    /**
     * Levenshtein distance for Arabic text
     */
    public function levenshtein_arabic($str1, $str2) {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);

        if ($len1 === 0) return $len2;
        if ($len2 === 0) return $len1;

        $matrix = [];

        for ($i = 0; $i <= $len1; $i++) {
            $matrix[$i] = [$i];
        }

        for ($j = 0; $j <= $len2; $j++) {
            $matrix[0][$j] = $j;
        }

        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                $cost = (mb_substr($str1, $i - 1, 1) === mb_substr($str2, $j - 1, 1)) ? 0 : 1;

                $matrix[$i][$j] = min(
                    $matrix[$i - 1][$j] + 1,      // deletion
                    $matrix[$i][$j - 1] + 1,      // insertion
                    $matrix[$i - 1][$j - 1] + $cost // substitution
                );
            }
        }

        return $matrix[$len1][$len2];
    }
}
