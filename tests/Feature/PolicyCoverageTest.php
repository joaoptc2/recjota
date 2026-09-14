<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Nenhum model acessível sem Policy (Seção 10). Se alguém adicionar um model e
 * esquecer a Policy, este teste quebra antes do código chegar em produção.
 */
class PolicyCoverageTest extends TestCase
{
    public function test_todo_model_tem_uma_policy_registrada(): void
    {
        $semPolicy = [];

        foreach ($this->models() as $model) {
            if (Gate::getPolicyFor($model) === null) {
                $semPolicy[] = $model;
            }
        }

        $this->assertSame([], $semPolicy, 'Models sem Policy: '.implode(', ', $semPolicy));
    }

    public function test_toda_policy_cobre_as_acoes_basicas(): void
    {
        $faltando = [];

        foreach ($this->models() as $model) {
            $policy = Gate::getPolicyFor($model);

            foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
                if (! method_exists($policy, $ability)) {
                    $faltando[] = class_basename($policy).'::'.$ability;
                }
            }
        }

        $this->assertSame([], $faltando, 'Ações ausentes: '.implode(', ', $faltando));
    }

    /** @return array<int, class-string<Model>> */
    private function models(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->depth(0)->name('*.php') as $file) {
            /** @var SplFileInfo $file */
            $class = 'App\\Models\\'.Str::before($file->getFilename(), '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }
}
