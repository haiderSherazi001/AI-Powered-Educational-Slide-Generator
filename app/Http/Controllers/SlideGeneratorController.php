<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class SlideGeneratorController extends Controller
{
    public function generate(Request $request)
    {
        // 1. VALIDATION
        $request->validate([
            'topic' => 'nullable|string',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp,epub,zip|max:10240',
        ]);

        $promptContext = "";

        try {
            // ==========================================
            // CASE A: USER UPLOADED A FILE
            // ==========================================
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $mimeType = $file->getMimeType();
                $originalName = $file->getClientOriginalName();

                // 1. PDF Handler
                if ($mimeType === 'application/pdf') {
                    $pdfParser = new Parser();
                    $pdf = $pdfParser->parseFile($file->getPathname());
                    $text = $pdf->getText();
                    $promptContext = "Analyze this document content: " . substr($text, 0, 15000);
                } 
                // 2. EPUB Handler
                elseif ($mimeType === 'application/epub+zip' || str_ends_with($originalName, '.epub')) {
                    if (!class_exists('ZipArchive')) {
                        return response()->json(['error' => 'Server Error: Zip extension missing'], 500);
                    }
                    $zip = new \ZipArchive();
                    $text = "";
                    if ($zip->open($file->getPathname()) === TRUE) {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = $zip->getNameIndex($i);
                            if (preg_match('/\.(xhtml|html|htm)$/i', $filename)) {
                                $text .= strip_tags($zip->getFromIndex($i)) . " ";
                            }
                            if (strlen($text) > 20000) break; 
                        }
                        $zip->close();
                    }
                    $promptContext = "Analyze this eBook content: " . substr($text, 0, 15000);
                }
                // 3. Image Handler (Groq is Text-Only, so we use a fallback)
                else {
                    $promptContext = "Create a presentation about this educational image's likely topic.";
                }
            } 
            // ==========================================
            // CASE B: USER TYPED A TOPIC
            // ==========================================
            elseif ($request->input('topic')) {
                $promptContext = "Create a detailed presentation about: " . $request->input('topic');
            } else {
                return response()->json(['error' => 'Please upload a file or enter a topic.'], 400);
            }

            // 2. Call Groq
            $slideData = $this->askGroq($promptContext);

            return response()->json($slideData);

        } catch (\Exception $e) {
            Log::error("Slide Gen Error: " . $e->getMessage());
            return response()->json(['error' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    private function askGroq($textPrompt)
    {
        $apiKey = env('GROQ_API_KEY');
        
        $systemInstruction = "
            You are an expert presentation creator. 
            Analyze the input and generate a presentation structure.
            
            CRITICAL RULES:
            1. Generate exactly 10 slides.
            2. EACH slide MUST have 3-4 bullet points.
            3. RETURN ONLY RAW JSON. DO NOT use Markdown formatting (no ```json ... ```).
            4. Do not add introductory text. Start with { and end with }.
            
            Structure: { \"presentation_title\": \"...\", \"slides\": [ { \"slide_number\": 1, \"title\": \"...\", \"bullet_points\": [\"...\", \"...\", \"...\", \"...\"], \"image_keyword\": \"...\" } ] }
        ";

        // Groq / OpenAI Compatible Request
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->post("https://api.groq.com/openai/v1/chat/completions", [
            'model' => 'llama-3.3-70b-versatile', // The Smart & Fast Model
            'messages' => [
                ['role' => 'system', 'content' => $systemInstruction],
                ['role' => 'user', 'content' => "INPUT CONTEXT: " . $textPrompt]
            ],
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'] // Forces valid JSON
        ]);

        if ($response->failed()) {
            throw new \Exception("Groq API Error: " . $response->body());
        }

        $responseData = $response->json();

        // FIX: Groq uses 'choices', not 'candidates'
        $rawText = $responseData['choices'][0]['message']['content'] ?? '{}';

        // Clean JSON just in case
        $cleanJson = str_replace(['```json', '```'], '', $rawText);
        
        $jsonData = json_decode($cleanJson, true);

        // Fallback if JSON is broken
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("JSON Decode Error: " . json_last_error_msg());
            Log::error("Raw AI Output: " . $rawText);
            
            return [
                "presentation_title" => "Error Parsing Slides",
                "slides" => [
                    [
                        "slide_number" => 1,
                        "title" => "Generation Failed",
                        "bullet_points" => [
                            "The AI generated invalid data.",
                            "Please try again with a slightly different topic.",
                            "Raw Error: " . json_last_error_msg()
                        ],
                        "image_keyword" => "error"
                    ]
                ]
            ];
        }
        
        return $jsonData;
    }
}