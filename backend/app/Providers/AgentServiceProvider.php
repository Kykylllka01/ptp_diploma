<?php

namespace App\Providers;

use App\Agents\Algo;
use App\Agents\Contracts;
use App\Agents\Fake;
use App\Agents\Llm;
use App\Agents\Text\DocumentTextExtractor;
use Illuminate\Support\ServiceProvider;

/**
 * Привязка интерфейсов агентов к реализациям по config('agents.driver'):
 *   llm  — настоящие агенты на языковой модели (Экзаменатор — алгоритм);
 *   fake — заготовки без нейросети.
 * Извлечение текста из файлов в обоих случаях настоящее.
 */
class AgentServiceProvider extends ServiceProvider
{
    private const DRIVERS = [
        'llm' => [
            Contracts\TextExtractor::class => DocumentTextExtractor::class,
            Contracts\AnalystAgent::class => Llm\LlmAnalystAgent::class,
            Contracts\WriterAgent::class => Llm\LlmWriterAgent::class,
            Contracts\CriticAgent::class => Llm\LlmCriticAgent::class,
            Contracts\CatalogAgent::class => Llm\LlmCatalogAgent::class,
            Contracts\GraderAgent::class => Llm\LlmGraderAgent::class,
            Contracts\ExaminerAgent::class => Algo\AlgorithmicExaminerAgent::class,
        ],
        'fake' => [
            Contracts\TextExtractor::class => DocumentTextExtractor::class,
            Contracts\AnalystAgent::class => Fake\FakeAnalystAgent::class,
            Contracts\WriterAgent::class => Fake\FakeWriterAgent::class,
            Contracts\CriticAgent::class => Fake\FakeCriticAgent::class,
            Contracts\CatalogAgent::class => Fake\FakeCatalogAgent::class,
            Contracts\GraderAgent::class => Fake\FakeGraderAgent::class,
            Contracts\ExaminerAgent::class => Fake\FakeExaminerAgent::class,
        ],
    ];

    public function register(): void
    {
        $driver = config('agents.driver', 'fake');
        foreach (self::DRIVERS[$driver] ?? self::DRIVERS['fake'] as $contract => $impl) {
            $this->app->bind($contract, $impl);
        }

        $this->app->singleton(Llm\HttpLlmClient::class, fn () => new Llm\HttpLlmClient(config('agents.llm')));
        $this->app->bind(Contracts\LlmClient::class, function () use ($driver) {
            return $driver === 'fake' ? new Fake\FakeLlmClient() : $this->app->make(Llm\HttpLlmClient::class);
        });
    }
}
