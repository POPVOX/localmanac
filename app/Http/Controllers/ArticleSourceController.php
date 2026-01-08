<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Response;

class ArticleSourceController extends Controller
{
    public function __invoke(Article $article): Response
    {
        $article->loadMissing('body');

        $text = $article->body?->cleaned_text ?? $article->body?->raw_text;

        return response($text ?? __('Source text unavailable.'), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
