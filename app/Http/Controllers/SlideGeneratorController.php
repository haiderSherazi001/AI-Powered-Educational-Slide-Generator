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
        // 1. VALIDATION: Allow PDF, Images, and EPUB
        $request->validate([
            'topic' => 'nullable|string',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp,epub,zip|max:10240', // 10MB
        ]);

        $promptContext = "";
        $imagePart = null;

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
                    $promptContext = "Analyze this document content: " . substr($text, 0, 30000);
                } 
                // 2. EPUB Handler (Safe Version)
                elseif ($mimeType === 'application/epub+zip' || str_ends_with($originalName, '.epub')) {
                    if (!class_exists('ZipArchive')) {
                        return response()->json(['error' => 'Server Error: Zip extension missing in php.ini'], 500);
                    }
                    
                    $zip = new \ZipArchive();
                    $text = "";
                    if ($zip->open($file->getPathname()) === TRUE) {
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = $zip->getNameIndex($i);
                            if (preg_match('/\.(xhtml|html|htm)$/i', $filename)) {
                                $text .= strip_tags($zip->getFromIndex($i)) . " ";
                            }
                            if (strlen($text) > 50000) break; 
                        }
                        $zip->close();
                    }
                    $promptContext = "Analyze this eBook content: " . substr($text, 0, 30000);
                }
                // 3. Image Handler
                else {
                    $imageData = base64_encode(file_get_contents($file->getPathname()));
                    $imagePart = [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $imageData
                        ]
                    ];
                    $promptContext = "Analyze this image. It is educational material. Extract key topics.";
                }
            } 
            // ==========================================
            // CASE B: USER TYPED A TOPIC
            // ==========================================
            elseif ($request->input('topic')) {
                // This logic was likely unreachable in your previous file
                $promptContext = "Create a detailed presentation about: " . $request->input('topic');
            } else {
                return response()->json(['error' => 'Please upload a file or enter a topic.'], 400);
            }

            // 2. Call Gemini
            $slideData = $this->askGemini($promptContext, $imagePart);

            return response()->json($slideData);

        } catch (\Exception $e) {
            Log::error("Slide Gen Error: " . $e->getMessage());
            return response()->json(['error' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    private function askGemini($textPrompt, $imagePart = null)
    {
        $apiKey = env('GEMINI_API_KEY');
        
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

        $parts = [
            ['text' => $systemInstruction . "\n\n INPUT CONTEXT: " . $textPrompt]
        ];

        if ($imagePart) {
            $parts[] = $imagePart;
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key={$apiKey}", [
            'contents' => [
                ['parts' => $parts]
            ]
        ]);

        $responseData = $response->json();

        if (isset($responseData['error'])) {
             throw new \Exception("Gemini API Error: " . $responseData['error']['message']);
        }

        $rawText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

        $cleanJson = str_replace(['```json', '```'], '', $rawText);
        
        $startIndex = strpos($cleanJson, '{');
        $endIndex = strrpos($cleanJson, '}');

        if ($startIndex !== false && $endIndex !== false) {
            $cleanJson = substr($cleanJson, $startIndex, ($endIndex - $startIndex) + 1);
        }

        $jsonData = json_decode($cleanJson, true);

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