<?php
class ChatRAG_Embeddings {
    
    /**
     * Genera un embedding simple basado en frecuencia de términos
     * Como no tenemos OpenAI, usamos un método simplificado
     */
    public function generateSimpleEmbedding($text) {
        $text = strtolower($text);
        $words = str_word_count($text, 1);
        $words = array_filter($words, function($word) {
            return strlen($word) > 3; // Ignorar palabras muy cortas
        });
        
        $freq = array_count_values($words);
        arsort($freq);
        
        // Tomar las 50 palabras más frecuentes como "embedding"
        $top_words = array_slice(array_keys($freq), 0, 50);
        
        return json_encode($top_words);
    }
    
    /**
     * Calcula similitud entre dos textos usando palabras clave
     */
    public function calculateSimilarity($text1, $text2) {
        $words1 = $this->extractKeywords($text1);
        $words2 = $this->extractKeywords($text2);
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        
        return count($intersection) / count($union);
    }
    
    private function extractKeywords($text) {
        $text = strtolower($text);
        $words = str_word_count($text, 1);
        return array_unique(array_filter($words, function($word) {
            return strlen($word) > 3;
        }));
    }
}