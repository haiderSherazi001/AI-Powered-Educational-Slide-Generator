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
        // 1. UPDATED VALIDATION: Now allows Images
        $request->validate([
            'topic' => 'nullable|string',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240', // 10MB Max
        ]);

        $promptContext = "";
        $imagePart = null; // We will fill this if it's an image

        try {
            // CASE A: User uploaded a file
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $mimeType = $file->getMimeType();

                // If it is a PDF -> Extract Text (Old Logic)
                if ($mimeType === 'application/pdf') {
                    $pdfParser = new Parser();
                    $pdf = $pdfParser->parseFile($file->getPathname());
                    $text = $pdf->getText();
                    $promptContext = "Analyze this document content: " . substr($text, 0, 15000);
                } 
                // If it is an IMAGE -> Prepare for Gemini Vision (New Logic)
                else {
                    $imageData = base64_encode(file_get_contents($file->getPathname()));
                    $imagePart = [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $imageData
                        ]
                    ];
                    $promptContext = "Analyze this image. It is educational material. Extract the key topics and create slides.";
                }
            } 
            // CASE B: User typed a topic
            elseif ($request->input('topic')) {
                $promptContext = "Create a presentation about: " . $request->input('topic');
            } else {
                return response()->json(['error' => 'Please upload a file or enter a topic.'], 400);
            }

            // 2. Call Gemini (Now with Image support)
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
        
        // Base instructions for JSON format
        $systemInstruction = "
            You are an expert presentation creator. 
            Analyze the input (text or image) and generate a 12-slide presentation structure.
            RETURN ONLY VALID JSON. No Markdown.
            Structure: { \"presentation_title\": \"...\", \"slides\": [ { \"slide_number\": 1, \"title\": \"...\", \"bullet_points\": [\"...\"], \"image_keyword\": \"...\" } ] }
        ";

        // Construct the API Payload
        $contents = [];
        
        // 1. Add the text instructions
        $parts = [
            ['text' => $systemInstruction . "\n\n INPUT CONTEXT: " . $textPrompt]
        ];

        // 2. Add the Image (if it exists)
        if ($imagePart) {
            $parts[] = $imagePart;
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key={$apiKey}", [
            'contents' => [
                ['parts' => $parts]
            ]
        ]);

        // 3. Parse Response
        $responseData = $response->json();

        if (isset($responseData['error'])) {
             throw new \Exception("Gemini API Error: " . $responseData['error']['message']);
        }

        $rawText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        
        // Clean markdown
        $cleanJson = str_replace(['```json', '```'], '', $rawText);
        
        return json_decode($cleanJson, true);
    }
}