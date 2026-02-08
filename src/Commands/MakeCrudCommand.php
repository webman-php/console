<?php

namespace Webman\Console\Commands;

use Doctrine\Inflector\InflectorFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Commands\Concerns\OrmTableCommandHelpers;
use Webman\Console\Util;
use Webman\Console\Messages;

#[AsCommand('make:crud', 'Make CRUD (Model, Controller, Validator)')]
class MakeCrudCommand extends Command
{
    use MakeCommandHelpers;
    use OrmTableCommandHelpers;

    protected function configure(): void
    {
        $this->addOption('table', 't', InputOption::VALUE_REQUIRED, $this->msg('opt_table'));
        $this->addOption('model', 'm', InputOption::VALUE_REQUIRED, $this->msg('opt_model'));
        $this->addOption('model-path', 'M', InputOption::VALUE_REQUIRED, $this->msg('opt_model_path'));
        $this->addOption('controller', 'c', InputOption::VALUE_REQUIRED, $this->msg('opt_controller'));
        $this->addOption('controller-path', 'C', InputOption::VALUE_REQUIRED, $this->msg('opt_controller_path'));
        // NOTE:
        // - `-v/-vv/-vvv` is reserved for Symfony Console verbosity (global option).
        // - `-V` is reserved for Symfony Console version (global option).
        // So validator name only supports long option `--validator`.
        $this->addOption('validator', null, InputOption::VALUE_REQUIRED, $this->msg('opt_validator'));
        $this->addOption('validator-path', null, InputOption::VALUE_REQUIRED, $this->msg('opt_validator_path'));
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, $this->msg('opt_plugin'));
        $this->addOption('orm', 'o', InputOption::VALUE_REQUIRED, $this->msg('opt_orm'));
        $this->addOption('database', 'd', InputOption::VALUE_OPTIONAL, $this->msg('opt_database'));
        $this->addOption('force', 'f', InputOption::VALUE_NONE, $this->msg('opt_force'));
        $this->addOption('no-validator', null, InputOption::VALUE_NONE, $this->msg('opt_no_validator'));
        $this->addOption('no-interaction', 'n', InputOption::VALUE_NONE, $this->msg('opt_no_interaction'));

        $this->setHelp($this->buildHelpText());

        $this->addUsage('');
        $this->addUsage('--table=users');
        $this->addUsage('--table=users --plugin=admin');
        $this->addUsage('--table=users --plugin=admin --force');
        $this->addUsage('--table=users --model-path=app/model --controller-path=app/controller');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = $this->normalizeOptionValue($input->getOption('table'));
        $modelOpt = $this->normalizeOptionValue($input->getOption('model'));
        $modelPath = $this->normalizeOptionValue($input->getOption('model-path'));
        $controllerOpt = $this->normalizeOptionValue($input->getOption('controller'));
        $controllerPath = $this->normalizeOptionValue($input->getOption('controller-path'));
        $validatorOpt = $this->normalizeOptionValue($input->getOption('validator'));
        $validatorPath = $this->normalizeOptionValue($input->getOption('validator-path'));
        $pluginOpt = $this->normalizeOptionValue($input->getOption('plugin'));
        $orm = $this->normalizeOptionValue($input->getOption('orm'));
        $database = $this->normalizeOptionValue($input->getOption('database'));
        $force = (bool)$input->getOption('force');
        $noValidator = (bool)$input->getOption('no-validator');
        $noInteraction = (bool)$input->getOption('no-interaction');

        if ($pluginOpt && (str_contains($pluginOpt, '/') || str_contains($pluginOpt, '\\'))) {
            $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $pluginOpt]));
            return Command::FAILURE;
        }

        $pluginByPath = $this->inferPluginFromPath($controllerPath)
            ?: $this->inferPluginFromPath($modelPath)
            ?: $this->inferPluginFromPath($validatorPath);
        $pluginOpt = $this->resolvePluginMismatchIfNeeded($input, $output, $noInteraction, $pluginOpt, $pluginByPath);
        if ($pluginOpt === false) {
            return Command::FAILURE;
        }
        $plugin = $pluginOpt ?: $pluginByPath;

        $validationEnabled = $this->isValidationEnabled();
        if ($validatorPath && !$validationEnabled && !$noValidator) {
            $output->writeln($this->msg('validation_not_enabled'));
            return Command::FAILURE;
        }

        $ormType = $this->resolveOrm($orm);
        [$ok, $connection] = $this->resolveAndValidateConnection($ormType, $plugin, $database, $output);
        if (!$ok) {
            return Command::FAILURE;
        }

        if (!$table) {
            if ($noInteraction) {
                $output->writeln($this->msg('table_required'));
                return Command::FAILURE;
            }
            $table = $this->promptForTable($input, $output, $ormType, $connection, 'Model');
            if (!$table) {
                $output->writeln($this->msg('table_required'));
                return Command::FAILURE;
            }
        }

        $modelNameDefault = $this->generateModelNameFromTable($table);
        $modelName = $this->resolveName($input, $output, $noInteraction, $modelOpt, $modelNameDefault, 'model');
        if (!$modelName) {
            $output->writeln($this->msg('invalid_name', ['{type}' => 'model']));
            return Command::FAILURE;
        }

        // Defer "Add validator?" prompt until right before validator name/path.
        // If user explicitly sets validator options, treat it as "yes" (unless --no-validator).
        $validatorExplicit = (bool)($validatorOpt || $validatorPath);
        $shouldAskValidator = $validationEnabled && !$noValidator && !$noInteraction && !$validatorExplicit;
        $shouldGenerateValidator = $validationEnabled && !$noValidator && ($noInteraction || $validatorExplicit);

        // Step 1: resolve model path
        $modelPathDefault = $this->getDefaultPath('model', $plugin);
        if (!$modelPath) {
            if ($noInteraction) {
                $modelPath = $modelPathDefault;
            } else {
                $modelPath = $this->promptForPathWithDefault($input, $output, 'model', $modelPathDefault);
            }
        }
        $modelPath = $this->normalizeRelativePath($modelPath);

        // After model path is known, infer plugin if not explicitly provided.
        $pluginByModelPath = $this->inferPluginFromPath($modelPath);
        if (!$plugin && $pluginByModelPath) {
            $plugin = $pluginByModelPath;
        }

        // Step 2: resolve controller name (depends on model name + suffix rules)
        $controllerPathDefault = $this->deriveSiblingPath($modelPath, 'model', 'controller');
        $controllerPlugin = $plugin ?: $this->inferPluginFromPath($controllerPathDefault);
        $suffix = $controllerPlugin
            ? (string)config("plugin.$controllerPlugin.app.controller_suffix", 'Controller')
            : (string)config('app.controller_suffix', 'Controller');
        $controllerNameDefault = $this->applySuffixToLastSegment($modelName, $suffix);
        $controllerName = $this->resolveName($input, $output, $noInteraction, $controllerOpt, $controllerNameDefault, 'controller');
        if (!$controllerName) {
            $output->writeln($this->msg('invalid_name', ['{type}' => 'controller']));
            return Command::FAILURE;
        }
        // Always ensure the controller suffix is applied once.
        $controllerName = $this->applySuffixToLastSegment($controllerName, $suffix);

        // Step 3: resolve controller path (default derived from model path)
        if (!$controllerPath) {
            if ($noInteraction) {
                $controllerPath = $controllerPathDefault;
            } else {
                $controllerPath = $this->promptForPathWithDefault($input, $output, 'controller', $controllerPathDefault);
            }
        }
        $controllerPath = $this->normalizeRelativePath($controllerPath);

        // If plugin is not explicitly provided, infer it from controller path (as required).
        $pluginByControllerPath = $this->inferPluginFromPath($controllerPath);
        if (!$plugin && $pluginByControllerPath) {
            $plugin = $pluginByControllerPath;
        }
        $pluginOpt = $this->resolvePluginMismatchIfNeeded($input, $output, $noInteraction, $pluginOpt, $pluginByControllerPath);
        if ($pluginOpt === false) {
            return Command::FAILURE;
        }

        if ($shouldAskValidator) {
            $shouldGenerateValidator = $this->promptForValidator($input, $output);
        }

        // Step 4: resolve validator name + path (derived from controller)
        $validatorName = '';
        if ($shouldGenerateValidator) {
            $baseForValidator = $this->stripSuffixFromLastSegment($controllerName, $suffix);
            $validatorNameDefault = $this->applySuffixToLastSegment($baseForValidator, 'Validator');
            $validatorName = $this->resolveName($input, $output, $noInteraction, $validatorOpt, $validatorNameDefault, 'validator');
            if (!$validatorName) {
                $output->writeln($this->msg('invalid_name', ['{type}' => 'validator']));
                return Command::FAILURE;
            }

            $validatorPathDefault = $this->deriveSiblingPath($controllerPath, 'controller', 'validation');
            if (!$validatorPath) {
                if ($noInteraction) {
                    $validatorPath = $validatorPathDefault;
                } else {
                    $validatorPath = $this->promptForPathWithDefault($input, $output, 'validation', $validatorPathDefault);
                }
            }
            $validatorPath = $this->normalizeRelativePath($validatorPath);
        }

        $resolvedModel = $this->resolveTargetByPath($modelName, $modelPath, $output);
        $modelClass = $resolvedModel[0] ?? null;
        $modelNamespace = $resolvedModel[1] ?? null;

        $results = [];
        $modelResult = $this->generateModel(
            $modelName,
            $modelPath,
            $table,
            $ormType,
            $connection,
            $force,
            $noInteraction,
            $input,
            $output
        );
        if ($modelResult !== null) {
            $results[] = $modelResult;
        }

        $validatorNamespace = null;
        if ($shouldGenerateValidator && $validatorPath && $validatorName) {
            $validatorResult = $this->generateValidator(
                $validatorName,
                $validatorPath,
                $table,
                $ormType,
                $connection,
                $force,
                $noInteraction,
                $input,
                $output
            );
            if ($validatorResult !== null) {
                $results[] = $validatorResult;
                $resolved = $this->resolveTargetByPath($validatorName, $validatorPath, $output);
                if ($resolved) {
                    $validatorNamespace = $resolved[1];
                }
            }
        }

        $controllerResult = $this->generateController(
            $controllerName,
            $controllerPath,
            $ormType,
            $force,
            $noInteraction,
            $input,
            $output,
            $validatorNamespace,
            $modelNamespace,
            $modelClass
        );
        if ($controllerResult !== null) {
            $results[] = $controllerResult;
        }

        if ($results === []) {
            $output->writeln($this->msg('nothing_generated'));
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln($this->msg('crud_generated', ['{count}' => (string)count($results)]));
        foreach ($results as $result) {
            $output->writeln('  ' . $this->msg('created', ['{path}' => $result]));
        }
        $output->writeln('');
        $output->writeln($this->msg('reference_only'));

        return Command::SUCCESS;
    }

    protected function isValidationEnabled(): bool
    {
        $middlewares = config('plugin.webman.validation.middleware');
        if (!is_array($middlewares) || $middlewares === []) {
            return false;
        }
        $class = 'Webman\\Validation\\Middleware\\ValidateMiddleware';
        if (!class_exists($class)) {
            return false;
        }
        foreach ($middlewares as $middleware) {
            if (!is_array($middleware)) {
                continue;
            }
            foreach ($middleware as $item) {
                $normalized = ltrim($item, '\\');
                if ($normalized === $class) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function generateModelNameFromTable(string $table): string
    {
        $inflector = InflectorFactory::create()->build();
        $table = ltrim(trim($table), '=');
        $singular = $inflector->singularize($table);
        return Util::nameToClass($singular);
    }

    protected function getDefaultPath(string $type, ?string $plugin): string
    {
        if ($plugin) {
            return "plugin/{$plugin}/app/{$type}";
        }
        return "app/{$type}";
    }

    protected function promptForPath(InputInterface $input, OutputInterface $output, string $type, ?string $plugin): string
    {
        $defaultPath = $this->getDefaultPath($type, $plugin);
        return $this->promptForPathWithDefault($input, $output, $type, $defaultPath);
    }

    protected function promptForPathWithDefault(InputInterface $input, OutputInterface $output, string $type, string $defaultPath): string
    {
        $defaultPath = $this->normalizeRelativePath($defaultPath);
        $label = $this->getTypeLabel($type);
        $helper = $this->getHelper('question');
        $question = new Question($this->msg('enter_path_prompt', ['{label}' => $label, '{default}' => $defaultPath]), $defaultPath);
        $path = $helper->ask($input, $output, $question);
        $path = is_string($path) ? $path : $defaultPath;
        return $this->normalizeRelativePath($path ?: $defaultPath);
    }

    /**
     * If --plugin conflicts with inferred plugin from a path:
     * - Non-interactive: error (cannot ask).
     * - Interactive: ask to continue; if user rejects, prompt for a new plugin name and re-check.
     *
     * @return string|false|null resolved plugin option (null means "no plugin")
     */
    protected function resolvePluginMismatchIfNeeded(
        InputInterface $input,
        OutputInterface $output,
        bool $noInteraction,
        string|null $pluginOpt,
        string|null $inferred
    ): string|false|null {
        $pluginOpt = $this->normalizeOptionValue($pluginOpt);
        $inferred = $this->normalizeOptionValue($inferred);
        if (!$pluginOpt || !$inferred || $pluginOpt === $inferred) {
            return $pluginOpt;
        }
        if ($noInteraction) {
            $output->writeln($this->msg('plugin_path_mismatch', [
                '{plugin}' => $pluginOpt,
                '{path_plugin}' => $inferred,
            ]));
            return false;
        }

        $helper = $this->getHelper('question');
        while (true) {
            $confirm = new ConfirmationQuestion(
                $this->msg('plugin_path_mismatch_confirm', [
                    '{plugin}' => $pluginOpt,
                    '{path_plugin}' => $inferred,
                ]),
                true
            );
            if ($helper->ask($input, $output, $confirm)) {
                return $pluginOpt;
            }

            $q = new Question($this->msg('plugin_reinput_prompt', ['{default}' => $inferred]), $inferred);
            $new = $helper->ask($input, $output, $q);
            $new = is_string($new) ? trim($new) : '';
            $new = $new !== '' ? $new : $inferred;
            if ($new && (str_contains($new, '/') || str_contains($new, '\\'))) {
                $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $new]));
                continue;
            }
            $pluginOpt = $new !== '' ? $new : null;
            if (!$pluginOpt || $pluginOpt === $inferred) {
                return $pluginOpt;
            }
            // Still mismatched -> loop again.
        }
    }

    protected function resolveName(
        InputInterface $input,
        OutputInterface $output,
        bool $noInteraction,
        ?string $provided,
        string $default,
        string $type
    ): string {
        $provided = $this->normalizeOptionValue($provided);
        $default = $this->normalizeOptionValue($default) ?: $default;
        if ($provided) {
            return $this->normalizeClassLikeName($provided);
        }
        if ($noInteraction) {
            return $this->normalizeClassLikeName($default);
        }
        $label = $this->getNameLabel($type);
        $helper = $this->getHelper('question');
        $question = new Question($this->msg('enter_name_prompt', ['{label}' => $label, '{default}' => $default]), $default);
        $answer = $helper->ask($input, $output, $question);
        $answer = is_string($answer) ? trim($answer) : '';
        $answer = $answer !== '' ? $answer : $default;
        return $this->normalizeClassLikeName($answer);
    }

    protected function getNameLabel(string $type): string
    {
        $labels = Util::selectLocaleMessages(Messages::getTypeLabels());
        return $labels[$type] ?? $type;
    }

    protected function normalizeClassLikeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        // Normalize separators for Windows/Unix inputs.
        $name = str_replace('\\', '/', $name);
        $name = trim($name, '/');
        // Keep segments but normalize each segment to StudlyCase.
        $segments = array_values(array_filter(explode('/', $name), static fn(string $s): bool => $s !== ''));
        $segments = array_map(static fn(string $seg): string => Util::nameToClass($seg), $segments);
        return implode('/', $segments);
    }

    protected function inferPluginFromPath(?string $path): ?string
    {
        $path = $this->normalizeOptionValue($path);
        if (!$path) {
            return null;
        }
        $path = $this->normalizeRelativePath($path);
        $path = trim($path, '/');
        if (preg_match('#^plugin/([^/]+)/#', $path, $m)) {
            return $m[1] !== '' ? $m[1] : null;
        }
        return null;
    }

    protected function deriveSiblingPath(string $path, string $from, string $to): string
    {
        $path = $this->normalizeRelativePath($path);
        $path = trim($path, '/');
        $from = trim($from);
        $to = trim($to);
        if ($from !== '' && preg_match('#/' . preg_quote($from, '#') . '$#i', $path)) {
            return preg_replace('#/' . preg_quote($from, '#') . '$#i', '/' . $to, $path) ?: $path;
        }
        return $path . '/' . $to;
    }

    protected function stripSuffixFromLastSegment(string $name, string $suffix): string
    {
        $name = str_replace('\\', '/', $name);
        $name = trim($name, '/');
        $suffix = trim($suffix);
        if ($suffix === '') {
            return $name;
        }
        $pos = strrpos($name, '/');
        if ($pos === false) {
            return str_ends_with($name, $suffix) ? substr($name, 0, -strlen($suffix)) : $name;
        }
        $prefix = substr($name, 0, $pos + 1);
        $last = substr($name, $pos + 1);
        if (str_ends_with($last, $suffix)) {
            $last = substr($last, 0, -strlen($suffix));
        }
        return $prefix . $last;
    }

    protected function getTypeLabel(string $type): string
    {
        $enTypeLabels = ['model' => 'Model', 'controller' => 'Controller', 'validation' => 'Validator'];
        $labels = Util::selectLocaleMessages([
            'zh_CN' => ['model' => '模型', 'controller' => '控制器', 'validation' => '验证器'],
            'zh_TW' => ['model' => '模型', 'controller' => '控制器', 'validation' => '驗證器'],
            'en' => $enTypeLabels,
            'ja' => ['model' => 'モデル', 'controller' => 'コントローラ', 'validation' => 'バリデータ'],
            'ko' => ['model' => '모델', 'controller' => '컨트롤러', 'validation' => '검증기'],
            'fr' => ['model' => 'Modèle', 'controller' => 'Contrôleur', 'validation' => 'Validateur'],
            'de' => ['model' => 'Modell', 'controller' => 'Controller', 'validation' => 'Validator'],
            'es' => ['model' => 'Modelo', 'controller' => 'Controlador', 'validation' => 'Validador'],
            'pt_BR' => ['model' => 'Modelo', 'controller' => 'Controlador', 'validation' => 'Validador'],
            'ru' => ['model' => 'Модель', 'controller' => 'Контроллер', 'validation' => 'Валидатор'],
            'vi' => ['model' => 'Model', 'controller' => 'Controller', 'validation' => 'Validator'],
            'tr' => ['model' => 'Model', 'controller' => 'Controller', 'validation' => 'Doğrulayıcı'],
            'id' => ['model' => 'Model', 'controller' => 'Controller', 'validation' => 'Validator'],
            'th' => ['model' => 'โมเดล', 'controller' => 'คอนโทรลเลอร์', 'validation' => 'ตัวตรวจสอบ'],
        ]);
        return $labels[$type] ?? $type;
    }

    protected function promptForValidator(InputInterface $input, OutputInterface $output): bool
    {
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            Util::selectByLocale(Messages::getValidatorPrompt()),
            true
        );
        return (bool)$helper->ask($input, $output, $question);
    }

    protected function generateModel(
        string $modelName,
        string $modelPath,
        string $table,
        string $ormType,
        ?string $connection,
        bool $force,
        bool $noInteraction,
        InputInterface $input,
        OutputInterface $output
    ): ?string {
        $resolved = $this->resolveTargetByPath($modelName, $modelPath, $output);
        if ($resolved === null) {
            return null;
        }
        [$class, $namespace, $file] = $resolved;

        if (is_file($file) && !$force) {
            if ($noInteraction || !$this->promptForOverride($input, $output, $file)) {
                return null;
            }
        }

        $modelCommand = new MakeModelCommand();
        if ($this->getApplication() !== null) {
            $modelCommand->setApplication($this->getApplication());
        }
        $reflection = new \ReflectionClass($modelCommand);
        $methodName = $ormType === self::ORM_THINKORM ? 'createTpModel' : 'createModel';
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        $method->invoke($modelCommand, $class, $namespace, $file, $connection, $table, $output);

        return $this->toRelativePath($file);
    }

    protected function generateController(
        string $controllerName,
        string $controllerPath,
        string $ormType,
        bool $force,
        bool $noInteraction,
        InputInterface $input,
        OutputInterface $output,
        ?string $validatorNamespace = null,
        ?string $modelNamespace = null,
        ?string $modelClass = null
    ): ?string {
        $resolved = $this->resolveTargetByPath($controllerName, $controllerPath, $output);
        if ($resolved === null) {
            return null;
        }
        [$class, $namespace, $file] = $resolved;

        if (is_file($file) && !$force) {
            if ($noInteraction || !$this->promptForOverride($input, $output, $file)) {
                return null;
            }
        }

        $this->createCrudController($class, $namespace, $file, $ormType, $validatorNamespace, $modelNamespace, $modelClass);
        return $this->toRelativePath($file);
    }

    protected function createCrudController(
        string $name,
        string $namespace,
        string $file,
        string $ormType,
        ?string $validatorNamespace = null,
        ?string $modelNamespace = null,
        ?string $modelClass = null
    ): void
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $modelName = $modelClass ?: str_replace('Controller', '', $name);
        $validatorName = $modelName . 'Validator';
        $useValidator = $validatorNamespace !== null && $this->isValidationEnabled();
        $isThinkOrm = $ormType === self::ORM_THINKORM;

        $uses = [
            'use support\Request;',
            'use support\Response;',
        ];
        if ($modelNamespace && $modelName) {
            $uses[] = "use {$modelNamespace}\\{$modelName};";
        }

        $useBlock = implode("\n", $uses);

        if ($useValidator) {
            $uses[] = "use {$validatorNamespace}\\{$validatorName};";
            $uses[] = 'use support\\validation\\Validate;';
            if ($isThinkOrm) {
                $uses[] = 'use think\\db\\exception\\DataNotFoundException;';
                $uses[] = 'use think\\db\\exception\\DbException;';
                $uses[] = 'use think\\db\\exception\\ModelNotFoundException;';
            }
            $useBlock = implode("\n", $uses);

            if ($isThinkOrm) {
                $controllerContent = <<<EOF
<?php

namespace $namespace;

$useBlock

class $name
{
    /**
     * Create
     * @param Request \$request
     * @return Response
     */
    #[Validate(validator: {$validatorName}::class, scene: 'create')]
    public function create(Request \$request): Response
    {
        \$data = \$request->post();
        \$model = new $modelName();
        \$model->save(\$data);
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Update
     * @param Request \$request
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    #[Validate(validator: {$validatorName}::class, scene: 'update')]
    public function update(Request \$request): Response
    {
        if (!\$model = $modelName::find(\$request->post('id'))) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$data = \$request->post();
        unset(\$data['id']);
        \$model->save(\$data);
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Delete
     * @param Request \$request
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    #[Validate(validator: {$validatorName}::class, scene: 'delete')]
    public function delete(Request \$request): Response
    {
        if (!\$model = $modelName::find(\$request->post('id'))) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$model->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * Detail
     * @param Request \$request
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    #[Validate(validator: {$validatorName}::class, scene: 'detail')]
    public function detail(Request \$request): Response
    {
        if (!\$model = $modelName::find(\$request->input('id'))) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }
}

EOF;
            } else {
                $controllerContent = <<<EOF
<?php

namespace $namespace;

$useBlock

class $name
{
    /**
     * Create
     * @param Request \$request
     * @return Response
     */
    #[Validate(validator: {$validatorName}::class, scene: 'create')]
    public function create(Request \$request): Response
    {
        \$data = \$request->post();
        \$model = new $modelName();
        foreach (\$data as \$key => \$value) {
            \$model->setAttribute(\$key, \$value);
        }
        \$model->save();
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Update
     * @param Request \$request
     * @return Response
     */
    #[Validate(validator: {$validatorName}::class, scene: 'update')]
    public function update(Request \$request): Response
    {
        if (!\$model = $modelName::find(\$request->post('id'))) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$data = \$request->post();
        unset(\$data['id']);
        foreach (\$data as \$key => \$value) {
            \$model->setAttribute(\$key, \$value);
        }
        \$model->save();
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Delete
     * @param Request \$request
     * @return Response
     */
    #[Validate(validator: {$validatorName}::class, scene: 'delete')]
    public function delete(Request \$request): Response
    {
        if (!\$model = $modelName::find(\$request->post('id'))) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$model->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * Detail
     * @param Request \$request
     * @return Response
     */
    #[Validate(validator: {$validatorName}::class, scene: 'detail')]
    public function detail(Request \$request): Response
    {
        if (!\$model = $modelName::find(\$request->input('id'))) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }
}

EOF;
            }
        } else {
            if ($isThinkOrm) {
                $uses[] = 'use think\\db\\exception\\DataNotFoundException;';
                $uses[] = 'use think\\db\\exception\\DbException;';
                $uses[] = 'use think\\db\\exception\\ModelNotFoundException;';
                $useBlock = implode("\n", $uses);

                $controllerContent = <<<EOF
<?php

namespace $namespace;

$useBlock

class $name
{
    /**
     * Create
     * @param Request \$request
     * @return Response
     */
    public function create(Request \$request): Response
    {
        \$data = \$request->post();
        \$model = new $modelName();
        \$model->save(\$data);
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Update
     * @param Request \$request
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function update(Request \$request): Response
    {
        \$id = \$request->post('id');
        if (!\$id) {
            return json(['code' => 1, 'msg' => 'missing id']);
        }
        if (!\$model = $modelName::find(\$id)) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$data = \$request->post();
        unset(\$data['id']);
        \$model->save(\$data);
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Delete
     * @param Request \$request
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function delete(Request \$request): Response
    {
        \$id = \$request->post('id');
        if (!\$id) {
            return json(['code' => 1, 'msg' => 'missing id']);
        }
        if (!\$model = $modelName::find(\$id)) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$model->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * Detail
     * @param Request \$request
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function detail(Request \$request): Response
    {
        \$id = \$request->input('id');
        if (!\$id) {
            return json(['code' => 1, 'msg' => 'missing id']);
        }
        if (!\$model = $modelName::find(\$id)) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }
}

EOF;
            } else {
                $controllerContent = <<<EOF
<?php

namespace $namespace;

$useBlock

class $name
{
    /**
     * Create
     * @param Request \$request
     * @return Response
     */
    public function create(Request \$request): Response
    {
        \$data = \$request->post();
        \$model = new $modelName();
        foreach (\$data as \$key => \$value) {
            \$model->setAttribute(\$key, \$value);
        }
        \$model->save();
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Update
     * @param Request \$request
     * @return Response
     */
    public function update(Request \$request): Response
    {
        \$id = \$request->post('id');
        if (!\$id) {
            return json(['code' => 1, 'msg' => 'missing id']);
        }
        if (!\$model = $modelName::find(\$id)) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$data = \$request->post();
        unset(\$data['id']);
        foreach (\$data as \$key => \$value) {
            \$model->setAttribute(\$key, \$value);
        }
        \$model->save();
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }

    /**
     * Delete
     * @param Request \$request
     * @return Response
     */
    public function delete(Request \$request): Response
    {
        \$id = \$request->post('id');
        if (!\$id) {
            return json(['code' => 1, 'msg' => 'missing id']);
        }
        if (!\$model = $modelName::find(\$id)) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        \$model->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * Detail
     * @param Request \$request
     * @return Response
     */
    public function detail(Request \$request): Response
    {
        \$id = \$request->input('id');
        if (!\$id) {
            return json(['code' => 1, 'msg' => 'missing id']);
        }
        if (!\$model = $modelName::find(\$id)) {
            return json(['code' => 1, 'msg' => 'not found']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => \$model]);
    }
}

EOF;
            }
        }
        file_put_contents($file, $controllerContent);
    }

    protected function generateValidator(
        string $validatorName,
        string $validatorPath,
        string $table,
        string $ormType,
        ?string $connection,
        bool $force,
        bool $noInteraction,
        InputInterface $input,
        OutputInterface $output
    ): ?string {
        if (!$this->isValidationEnabled()) {
            return null;
        }

        $resolved = $this->resolveTargetByPath($validatorName, $validatorPath, $output);
        if ($resolved === null) {
            return null;
        }
        [$class, $namespace, $file] = $resolved;

        if (is_file($file) && !$force) {
            if ($noInteraction || !$this->promptForOverride($input, $output, $file)) {
                return null;
            }
        }

        $this->createValidatorFile($class, $namespace, $file, $table, $ormType, $connection, $output);
        return $this->toRelativePath($file);
    }

    protected function createValidatorFile(
        string $class,
        string $namespace,
        string $file,
        string $table,
        string $ormType,
        ?string $connection,
        OutputInterface $output
    ): void {
        if (!class_exists('Webman\\Validation\\Command\\ValidatorGenerator\\Support\\ValidatorClassRenderer')) {
            $this->createSimpleValidatorFile($class, $namespace, $file);
            return;
        }

        try {
            $detector = new \Webman\Validation\Command\ValidatorGenerator\Support\OrmDetector();
            $orm = $detector->resolve($ormType === self::ORM_THINKORM ? 'thinkorm' : 'laravel');
            if (!in_array($orm, ['laravel', 'thinkorm'], true)) {
                $this->createSimpleValidatorFile($class, $namespace, $file);
                return;
            }

            $resolver = $orm === 'thinkorm'
                ? new \Webman\Validation\Command\ValidatorGenerator\ThinkOrm\ThinkOrmConnectionResolver()
                : new \Webman\Validation\Command\ValidatorGenerator\Illuminate\IlluminateConnectionResolver();
            $conn = $resolver->resolve($connection);

            $factory = new \Webman\Validation\Command\ValidatorGenerator\Support\SchemaIntrospectorFactory();
            $introspector = $factory->createForDriver($conn->driverName());
            $tableDef = $introspector->introspect($conn, $table);

            $excludeColumns = $orm === 'thinkorm'
                ? \Webman\Validation\Command\ValidatorGenerator\Support\ExcludedColumns::defaultForThinkOrm()
                : \Webman\Validation\Command\ValidatorGenerator\Support\ExcludedColumns::defaultForIlluminate();
            $inferrer = new \Webman\Validation\Command\ValidatorGenerator\Rules\DefaultRuleInferrer();
            $result = $inferrer->infer($tableDef, [
                'exclude_columns' => $excludeColumns,
                'with_scenes' => true,
                'scenes' => 'crud',
            ]);

            $rules = $result['rules'] ?? [];
            $attributes = $result['attributes'] ?? [];
            $scenes = $result['scenes'] ?? [];

            $renderer = new \Webman\Validation\Command\ValidatorGenerator\Support\ValidatorClassRenderer();
            $content = $renderer->render($namespace, $class, $rules, [], $attributes, $scenes);
            (new \Webman\Validation\Command\ValidatorGenerator\Support\ValidatorFileWriter())->write($file, $content);
        } catch (\Throwable $e) {
            $output->writeln($this->msg('validator_failed', ['{reason}' => $e->getMessage()]));
            $this->createSimpleValidatorFile($class, $namespace, $file);
        }
    }

    protected function createSimpleValidatorFile(string $class, string $namespace, string $file): void
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $content = <<<EOF
<?php

namespace $namespace;

use Webman\\Validation\\Validator;

class $class extends Validator
{
    public function rules(): array
    {
        return [];
    }
}

EOF;
        file_put_contents($file, $content);
    }

    protected function promptForOverride(InputInterface $input, OutputInterface $output, string $file): bool
    {
        $relative = $this->toRelativePath($file);
        $helper = $this->getHelper('question');
        $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
        $question = new ConfirmationQuestion($prompt, true);
        return (bool)$helper->ask($input, $output, $question);
    }

    /**
     * Resolve target by relative path only (no plugin/path conflict).
     *
     * @param string $name
     * @param string $path
     * @param OutputInterface $output
     * @return array{0:string,1:string,2:string}|null [class, namespace, file]
     */
    protected function resolveTargetByPath(string $name, string $path, OutputInterface $output): ?array
    {
        $pathNorm = $this->normalizeRelativePath($path);
        if ($this->isAbsolutePath($pathNorm)) {
            $output->writeln($this->msg('invalid_path', ['{path}' => $path]));
            return null;
        }

        $targetDir = base_path($pathNorm);
        $namespaceRoot = trim(str_replace('/', '\\', $pathNorm), '\\');
        $name = str_replace('\\', '/', $name);
        $name = trim($name, '/');

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $subPath = '';
        } else {
            $subPath = substr($name, 0, $pos);
            $class = ucfirst(substr($name, $pos + 1));
        }

        $subDir = $subPath ? str_replace('/', DIRECTORY_SEPARATOR, $subPath) . DIRECTORY_SEPARATOR : '';
        $file = $targetDir . DIRECTORY_SEPARATOR . $subDir . $class . '.php';
        $namespace = $namespaceRoot . ($subPath ? '\\' . str_replace('/', '\\', $subPath) : '');
        return [$class, $namespace, $file];
    }

    protected function applySuffixToLastSegment(string $name, string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return $name;
        }
        $pos = strrpos($name, '/');
        if ($pos === false) {
            return str_ends_with($name, $suffix) ? $name : ($name . $suffix);
        }
        $prefix = substr($name, 0, $pos + 1);
        $last = substr($name, $pos + 1);
        if (!str_ends_with($last, $suffix)) {
            $last .= $suffix;
        }
        return $prefix . $last;
    }

    protected function msg(string $key, array $replace = []): string
    {
        return strtr(Util::selectLocaleMessages(Messages::getMakeCrudMessages()[$key] ?? $key), $replace);
    }

    protected function buildHelpText(): string
    {
        return Util::selectLocaleMessages(Messages::getMakeCrudHelpText());
    }
}
