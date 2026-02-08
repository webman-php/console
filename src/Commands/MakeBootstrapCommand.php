<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Webman\Console\Util;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;

#[AsCommand('make:bootstrap', 'Make a bootstrap.')]
class MakeBootstrapCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Bootstrap name');
        $this->addArgument('enable', InputArgument::OPTIONAL, 'Enable or not');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/bootstrap');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        $this->setHelp($this->buildHelpText());

        $this->addUsage('MyBootstrap');
        $this->addUsage('MyBootstrap no');
        $this->addUsage('MyBootstrap -p admin');
        $this->addUsage('MyBootstrap -P plugin/admin/app/bootstrap');
        $this->addUsage('MyBootstrap -f');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = Util::nameToClass((string)$input->getArgument('name'));
        $enable = $this->parseEnableArgument($input->getArgument('enable'));
        $plugin = $this->normalizeOptionValue($input->getOption('plugin'));
        $path = $this->normalizeOptionValue($input->getOption('path'));
        $force = (bool)$input->getOption('force');

        if ($plugin && (str_contains($plugin, '/') || str_contains($plugin, '\\'))) {
            $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $plugin]));
            return Command::FAILURE;
        }

        $name = str_replace('\\', '/', $name);

        if ($plugin || $path) {
            $resolved = $this->resolveTargetByPluginOrPath(
                $name,
                $plugin,
                $path,
                $output,
                fn(string $p) => $this->getPluginBootstrapRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$class, $namespace, $file] = $resolved;
        } else {
            [$class, $namespace, $file] = $this->resolveAppBootstrapTarget($name);
        }

        $output->writeln($this->msg('make_bootstrap', ['{name}' => $class]));

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        $this->createBootstrap($class, $namespace, $file);
        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));

        if ($enable) {
            $bootstrapClass = "{$namespace}\\{$class}";
            $configFile = $plugin
                ? base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php')
                : (config_path() . '/bootstrap.php');

            $changed = $this->addClassToFlatClassListConfig($configFile, $bootstrapClass);
            if ($changed) {
                $output->writeln($this->msg('enabled', ['{class}' => $bootstrapClass]));
            } else {
                $output->writeln($this->msg('enabled_exists', ['{class}' => $bootstrapClass]));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolve bootstrap namespace/file path under app/ (backward compatible).
     *
     * @param string $name
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    protected function resolveAppBootstrapTarget(string $name): array
    {
        $bootstrapStr = Util::guessPath(app_path(), 'bootstrap');
        if (!$bootstrapStr) {
            $bootstrapStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Bootstrap' : 'bootstrap';
        }
        $upper = $bootstrapStr === 'Bootstrap';

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $file = app_path() . DIRECTORY_SEPARATOR . $bootstrapStr . DIRECTORY_SEPARATOR . "{$class}.php";
            $namespace = $upper ? 'App\Bootstrap' : 'app\bootstrap';
            return [$class, $namespace, $file];
        }

        $dirPart = substr($name, 0, $pos);
        $realDirPart = Util::guessPath(app_path(), $dirPart);
        if ($realDirPart) {
            $dirPart = str_replace(DIRECTORY_SEPARATOR, '/', $realDirPart);
        } else if ($upper) {
            $dirPart = preg_replace_callback('/\/([a-z])/', static function ($matches) {
                return '/' . strtoupper($matches[1]);
            }, ucfirst($dirPart));
        }

        $path = "{$bootstrapStr}/{$dirPart}";
        $class = ucfirst(substr($name, $pos + 1));
        $file = app_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginBootstrapRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $bootstrapDir = Util::guessPath($appDir, 'bootstrap');
        if (!$bootstrapDir) {
            $bootstrapDir = Util::guessPath($appDir, 'controller') === 'Controller' ? 'Bootstrap' : 'bootstrap';
        }
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$bootstrapDir}");
    }

    /**
     * @param $name
     * @param $namespace
     * @param $file
     * @return void
     */
    protected function createBootstrap($name, $namespace, $file)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $bootstrap_content = <<<EOF
<?php

namespace $namespace;

use Webman\Bootstrap;

class $name implements Bootstrap
{
    public static function start(\$worker)
    {
        // Is it console environment ?
        \$is_console = !\$worker;
        if (\$is_console) {
            // If you do not want to execute this in console, just return.
            return;
        }


    }

}

EOF;
        file_put_contents($file, $bootstrap_content);
    }

    public function addConfig($class, $config_file)
    {
        $config = include $config_file;
        if(!in_array($class, $config ?? [])) {
            $config_file_content = file_get_contents($config_file);
            $config_file_content = preg_replace('/\];/', "    $class::class,\n];", $config_file_content);
            file_put_contents($config_file, $config_file_content);
        }
    }

    /**
     * Parse positional `enable` argument (backward compatible).
     *
     * @param mixed $value
     * @return bool
     */
    protected function parseEnableArgument(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $v = strtolower(trim((string)$value));
        return !in_array($v, ['no', '0', 'false', 'n', 'off', 'disable', 'disabled'], true);
    }

    /**
     * Hardcoded CLI messages (bilingual) without translation module.
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    protected function msg(string $key, array $replace = []): string
    {
        $zh = [
            'make_bootstrap' => '<info>创建启动项</info> <comment>{name}</comment>',
            'created' => '<info>已创建：</info> {path}',
            'enabled' => '<info>已启用：</info> {class}',
            'enabled_exists' => '<comment>[Info]</comment> 已存在，无需重复写入：{class}',
            'override_prompt' => "<question>文件已存在：{path}</question>\n<question>是否覆盖？[Y/n]（回车=Y）</question>\n",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
        ];

        $en = [
            'make_bootstrap' => '<info>Make bootstrap</info> <comment>{name}</comment>',
            'created' => '<info>Created:</info> {path}',
            'enabled' => '<info>Enabled:</info> {class}',
            'enabled_exists' => '<comment>[Info]</comment> Already exists, skipped: {class}',
            'override_prompt' => "<question>File already exists: {path}</question>\n<question>Override? [Y/n] (Enter = Y)</question>\n",
            'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
            'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
        ];

        $messages = [
            'zh_CN' => $zh, 'zh_TW' => [
                'make_bootstrap' => '<info>建立啟動項</info> <comment>{name}</comment>',
                'created' => '<info>已建立：</info> {path}',
                'enabled' => '<info>已啟用：</info> {class}',
                'enabled_exists' => '<comment>[Info]</comment> 已存在，無需重複寫入：{class}',
                'override_prompt' => "<question>檔案已存在：{path}</question>\n<question>是否覆蓋？[Y/n]（Enter=Y）</question>\n",
                'invalid_plugin' => '<error>插件名稱無效：{plugin}。`--plugin/-p` 只能是 plugin/ 目錄下的目錄名，不能包含 / 或 \\。</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` 與 `--path/-P` 同時指定且不一致。\n期望路徑：{expected}\n實際路徑：{actual}\n請二選一或保持一致。</error>",
                'invalid_path' => '<error>路徑無效：{path}。`--path/-P` 必須是相對路徑（相對於專案根目錄），不能是絕對路徑。</error>',
            ],
            'en' => $en,
            'ja' => [
                'make_bootstrap' => '<info>Bootstrap を作成</info> <comment>{name}</comment>',
                'created' => '<info>作成しました：</info> {path}',
                'enabled' => '<info>有効にしました：</info> {class}',
                'enabled_exists' => '<comment>[Info]</comment> 既に存在します、スキップ：{class}',
                'override_prompt' => "<question>ファイルが既に存在します：{path}</question>\n<question>上書きしますか？[Y/n]（Enter=Y）</question>\n",
                'invalid_plugin' => '<error>プラグイン名が無効です：{plugin}。`--plugin/-p` は plugin/ 以下のディレクトリ名で、/ または \\ を含めません。</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` と `--path/-P` が両方指定されていますが一致しません。\n期待：{expected}\n実際：{actual}\nどちらか一方に揃えてください。</error>",
                'invalid_path' => '<error>パスが無効です：{path}。`--path/-P` はプロジェクトルートからの相対パスで、絶対パスは不可です。</error>',
            ],
            'ko' => ['make_bootstrap' => '<info>Bootstrap 생성</info> <comment>{name}</comment>', 'created' => '<info>생성됨:</info> {path}', 'enabled' => '<info>활성화됨:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> 이미 있음, 건너뜀: {class}', 'override_prompt' => "<question>파일이 이미 있습니다: {path}</question>\n<question>덮어쓸까요? [Y/n] (Enter=Y)</question>\n", 'invalid_plugin' => '<error>잘못된 플러그인 이름: {plugin}. `--plugin/-p`는 plugin/ 아래 디렉터리 이름이며 / 또는 \\를 포함할 수 없습니다.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p`와 `--path/-P`가 모두 지정되었지만 일치하지 않습니다.\n예상: {expected}\n실제: {actual}\n하나만 사용하거나 동일하게 맞추세요.</error>", 'invalid_path' => '<error>잘못된 경로: {path}. `--path/-P`는 프로젝트 루트 기준 상대 경로여야 하며 절대 경로는 안 됩니다.</error>'],
            'fr' => ['make_bootstrap' => '<info>Créer un Bootstrap</info> <comment>{name}</comment>', 'created' => '<info>Créé :</info> {path}', 'enabled' => '<info>Activé :</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Déjà présent, ignoré : {class}', 'override_prompt' => "<question>Le fichier existe déjà : {path}</question>\n<question>Écraser ? [Y/n] (Entrée = Y)</question>\n", 'invalid_plugin' => '<error>Nom de plugin invalide : {plugin}. `--plugin/-p` doit être un nom de dossier sous plugin/, sans / ni \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` et `--path/-P` sont tous deux fournis mais incohérents.\nAttendu : {expected}\nRéel : {actual}\nN\'en fournissez qu\'un ou rendez-les identiques.</error>", 'invalid_path' => '<error>Chemin invalide : {path}. `--path/-P` doit être un chemin relatif (à la racine du projet), pas absolu.</error>'],
            'de' => ['make_bootstrap' => '<info>Bootstrap erstellen</info> <comment>{name}</comment>', 'created' => '<info>Erstellt:</info> {path}', 'enabled' => '<info>Aktiviert:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Bereits vorhanden, übersprungen: {class}', 'override_prompt' => "<question>Datei existiert bereits: {path}</question>\n<question>Überschreiben? [Y/n] (Eingabe = Y)</question>\n", 'invalid_plugin' => '<error>Ungültiger Plugin-Name: {plugin}. `--plugin/-p` muss ein Ordnername unter plugin/ sein, ohne / oder \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` und `--path/-P` sind beide angegeben, aber nicht konsistent.\nErwartet: {expected}\nTatsächlich: {actual}\nNur eines angeben oder angleichen.</error>", 'invalid_path' => '<error>Ungültiger Pfad: {path}. `--path/-P` muss ein relativer Pfad (zur Projektwurzel) sein, kein absoluter.</error>'],
            'es' => ['make_bootstrap' => '<info>Crear Bootstrap</info> <comment>{name}</comment>', 'created' => '<info>Creado:</info> {path}', 'enabled' => '<info>Habilitado:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Ya existe, omitido: {class}', 'override_prompt' => "<question>El archivo ya existe: {path}</question>\n<question>¿Sobrescribir? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Nombre de plugin no válido: {plugin}. `--plugin/-p` debe ser un nombre de carpeta bajo plugin/, sin / ni \\.</error>', 'plugin_path_conflict' => "<error>Se dieron `--plugin/-p` y `--path/-P` pero no coinciden.\nEsperado: {expected}\nReal: {actual}\nProporcione solo uno o hágalos idénticos.</error>", 'invalid_path' => '<error>Ruta no válida: {path}. `--path/-P` debe ser una ruta relativa (a la raíz del proyecto), no absoluta.</error>'],
            'pt_BR' => ['make_bootstrap' => '<info>Criar Bootstrap</info> <comment>{name}</comment>', 'created' => '<info>Criado:</info> {path}', 'enabled' => '<info>Habilitado:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Já existe, ignorado: {class}', 'override_prompt' => "<question>O arquivo já existe: {path}</question>\n<question>Sobrescrever? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Nome de plugin inválido: {plugin}. `--plugin/-p` deve ser um nome de pasta em plugin/, sem / ou \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` e `--path/-P` foram fornecidos mas são inconsistentes.\nEsperado: {expected}\nAtual: {actual}\nForneça apenas um ou deixe-os iguais.</error>", 'invalid_path' => '<error>Caminho inválido: {path}. `--path/-P` deve ser um caminho relativo (à raiz do projeto), não absoluto.</error>'],
            'ru' => ['make_bootstrap' => '<info>Создать Bootstrap</info> <comment>{name}</comment>', 'created' => '<info>Создано:</info> {path}', 'enabled' => '<info>Включено:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Уже есть, пропущено: {class}', 'override_prompt' => "<question>Файл уже существует: {path}</question>\n<question>Перезаписать? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Недопустимое имя плагина: {plugin}. `--plugin/-p` должно быть именем каталога в plugin/, без / или \\.</error>', 'plugin_path_conflict' => "<error>Указаны и `--plugin/-p`, и `--path/-P`, но они не совпадают.\nОжидалось: {expected}\nФактически: {actual}\nУкажите что-то одно или сделайте их одинаковыми.</error>", 'invalid_path' => '<error>Недопустимый путь: {path}. `--path/-P` должен быть относительным путём (к корню проекта), не абсолютным.</error>'],
            'vi' => ['make_bootstrap' => '<info>Tạo Bootstrap</info> <comment>{name}</comment>', 'created' => '<info>Đã tạo:</info> {path}', 'enabled' => '<info>Đã bật:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Đã tồn tại, bỏ qua: {class}', 'override_prompt' => "<question>Tệp đã tồn tại: {path}</question>\n<question>Ghi đè? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Tên plugin không hợp lệ: {plugin}. `--plugin/-p` phải là tên thư mục trong plugin/, không chứa / hoặc \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` và `--path/-P` đều được chỉ định nhưng không khớp.\nMong đợi: {expected}\nThực tế: {actual}\nChỉ dùng một hoặc cho chúng giống nhau.</error>", 'invalid_path' => '<error>Đường dẫn không hợp lệ: {path}. `--path/-P` phải là đường dẫn tương đối (tới thư mục gốc dự án), không phải tuyệt đối.</error>'],
            'tr' => ['make_bootstrap' => '<info>Bootstrap oluştur</info> <comment>{name}</comment>', 'created' => '<info>Oluşturuldu:</info> {path}', 'enabled' => '<info>Etkinleştirildi:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Zaten mevcut, atlandı: {class}', 'override_prompt' => "<question>Dosya zaten mevcut: {path}</question>\n<question>Üzerine yazılsın mı? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Geçersiz eklenti adı: {plugin}. `--plugin/-p` plugin/ altında bir klasör adı olmalı, / veya \\ içermemeli.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` ve `--path/-P` birlikte verilmiş ancak uyuşmuyor.\nBeklenen: {expected}\nGerçek: {actual}\nYalnızca birini verin veya aynı yapın.</error>", 'invalid_path' => '<error>Geçersiz yol: {path}. `--path/-P` proje köküne göre göreli yol olmalı, mutlak yol olmamalı.</error>'],
            'id' => ['make_bootstrap' => '<info>Buat Bootstrap</info> <comment>{name}</comment>', 'created' => '<info>Dibuat:</info> {path}', 'enabled' => '<info>Diaktifkan:</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> Sudah ada, dilewati: {class}', 'override_prompt' => "<question>File sudah ada: {path}</question>\n<question>Timpa? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Nama plugin tidak valid: {plugin}. `--plugin/-p` harus nama folder di bawah plugin/, tidak boleh mengandung / atau \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` dan `--path/-P` keduanya diberikan tetapi tidak konsisten.\nHarapan: {expected}\nActual: {actual}\nBerikan hanya satu atau samakan.</error>", 'invalid_path' => '<error>Path tidak valid: {path}. `--path/-P` harus path relatif (ke akar proyek), bukan absolut.</error>'],
            'th' => ['make_bootstrap' => '<info>สร้าง Bootstrap</info> <comment>{name}</comment>', 'created' => '<info>สร้างแล้ว：</info> {path}', 'enabled' => '<info>เปิดใช้งานแล้ว：</info> {class}', 'enabled_exists' => '<comment>[Info]</comment> มีอยู่แล้ว ข้าม：{class}', 'override_prompt' => "<question>มีไฟล์อยู่แล้ว：{path}</question>\n<question>เขียนทับ？[Y/n]（Enter=Y）</question>\n", 'invalid_plugin' => '<error>ชื่อปลั๊กอินไม่ถูกต้อง：{plugin}。`--plugin/-p` ต้องเป็นชื่อโฟลเดอร์ภายใต้ plugin/ ห้ามมี / หรือ \\</error>', 'plugin_path_conflict' => "<error>ระบุทั้ง `--plugin/-p` และ `--path/-P` แต่ไม่ตรงกัน\nคาดว่า：{expected}\nจริง：{actual}\nใช้อย่างใดอย่างหนึ่งหรือให้ตรงกัน</error>", 'invalid_path' => '<error>เส้นทางไม่ถูกต้อง：{path}。`--path/-P` ต้องเป็นเส้นทางสัมพัทธ์（จากรากโปรเจกต์）ไม่ใช่แบบสัมบูรณ์</error>'],
        ];
        $map = Util::selectLocaleMessages($messages);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }

    /**
     * Command help text (multilingual).
     *
     * @return string
     */
    protected function buildHelpText(): string
    {
        
        return Util::selectByLocale([
            'zh_CN' => "生成 Bootstrap 启动项类（实现 Webman\\Bootstrap）。\n\n推荐用法：\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\n说明：\n  - 默认生成到 app/bootstrap（大小写以现有目录为准）。\n  - 使用 -p/--plugin 时默认生成到 plugin/<plugin>/app/bootstrap。\n  - 使用 -P/--path 时生成到指定相对目录（相对于项目根目录）。\n  - enable 位置参数用于控制是否写入 config/bootstrap.php（默认启用；传 no/false/0/off 等表示不启用）。\n  - 文件已存在时默认会提示是否覆盖；使用 -f/--force 可直接覆盖。",
            'zh_TW' => "建立 Bootstrap 啟動項類（實作 Webman\\Bootstrap）。\n\n推薦用法：\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\n說明：\n  - 預設生成到 app/bootstrap（大小寫依現有目錄）。\n  - 使用 -p/--plugin 時預設生成到 plugin/<plugin>/app/bootstrap。\n  - 使用 -P/--path 時生成到指定相對目錄（相對於專案根目錄）。\n  - enable 位置參數用於控制是否寫入 config/bootstrap.php（預設啟用；傳 no/false/0/off 表示不啟用）。\n  - 檔案已存在時會詢問是否覆蓋；使用 -f/--force 可直接覆蓋。",
            'en' => "Generate a Bootstrap class (implements Webman\\Bootstrap).\n\nRecommended:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nNotes:\n  - By default, it generates under app/bootstrap (case depends on existing directory).\n  - With -p/--plugin, it generates under plugin/<plugin>/app/bootstrap by default.\n  - With -P/--path, it generates under the specified relative directory (to project root).\n  - The positional `enable` argument controls whether to append to config/bootstrap.php (enabled by default; use no/false/0/off to disable).\n  - If the file already exists, it will ask before overriding; use -f/--force to override directly.",
            'ja' => "Bootstrap クラスを生成（Webman\\Bootstrap を実装）。\n\n推奨：\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\n説明：\n  - デフォルトは app/bootstrap に生成（大文字小文字は既存ディレクトリに合わせる）。\n  - -p/--plugin の場合は plugin/<plugin>/app/bootstrap に生成。\n  - -P/--path で相対ディレクトリを指定可能（プロジェクトルート基準）。\n  - 位置引数 enable で config/bootstrap.php に追記するか制御（デフォルト有効；no/false/0/off で無効）。\n  - ファイルが既にある場合は上書き確認；-f/--force で直接上書き。",
            'ko' => "Bootstrap 클래스 생성 (Webman\\Bootstrap 구현).\n\n권장:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\n참고:\n  - 기본 생성 위치 app/bootstrap(기존 디렉터리 대소문자 따름).\n  - -p/--plugin 사용 시 plugin/<plugin>/app/bootstrap에 생성.\n  - -P/--path로 프로젝트 루트 기준 상대 경로 지정 가능.\n  - 위치 인자 enable으로 config/bootstrap.php 추가 여부 제어(기본 활성화; no/false/0/off로 비활성화).\n  - 파일이 있으면 덮어쓸지 묻고, -f/--force로 직접 덮어쓰기.",
            'fr' => "Générer une classe Bootstrap (implémente Webman\\Bootstrap).\n\nRecommandé :\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nNotes :\n  - Par défaut, génération sous app/bootstrap (casse selon le répertoire existant).\n  - Avec -p/--plugin, génération sous plugin/<plugin>/app/bootstrap.\n  - Avec -P/--path, génération dans le répertoire relatif indiqué (par rapport à la racine).\n  - L'argument positionnel enable contrôle l'ajout à config/bootstrap.php (activé par défaut ; no/false/0/off pour désactiver).\n  - Si le fichier existe, demande avant d'écraser ; -f/--force pour écraser directement.",
            'de' => "Eine Bootstrap-Klasse erzeugen (implementiert Webman\\Bootstrap).\n\nEmpfohlen:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nHinweise:\n  - Standard: Erzeugung unter app/bootstrap (Groß-/Kleinschreibung nach vorhandenem Verzeichnis).\n  - Mit -p/--plugin: unter plugin/<plugin>/app/bootstrap.\n  - Mit -P/--path: unter angegebenem relativem Pfad (zur Projektwurzel).\n  - Positionsargument enable steuert Eintrag in config/bootstrap.php (Standard: an; no/false/0/off zum Deaktivieren).\n  - Bei vorhandener Datei wird gefragt; -f/--force überschreibt direkt.",
            'es' => "Generar una clase Bootstrap (implementa Webman\\Bootstrap).\n\nRecomendado:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nNotas:\n  - Por defecto se genera en app/bootstrap (mayúsculas según el directorio existente).\n  - Con -p/--plugin se genera en plugin/<plugin>/app/bootstrap.\n  - Con -P/--path se genera en el directorio relativo indicado (respecto a la raíz).\n  - El argumento posicional enable controla si se escribe en config/bootstrap.php (activado por defecto; no/false/0/off para desactivar).\n  - Si el archivo existe, pregunta antes de sobrescribir; -f/--force sobrescribe directamente.",
            'pt_BR' => "Gerar uma classe Bootstrap (implementa Webman\\Bootstrap).\n\nRecomendado:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nNotas:\n  - Por padrão gera em app/bootstrap (maiúsculas conforme o diretório existente).\n  - Com -p/--plugin gera em plugin/<plugin>/app/bootstrap.\n  - Com -P/--path gera no diretório relativo indicado (em relação à raiz do projeto).\n  - O argumento posicional enable controla se grava em config/bootstrap.php (ativado por padrão; no/false/0/off para desativar).\n  - Se o arquivo existir, pergunta antes de sobrescrever; -f/--force sobrescreve diretamente.",
            'ru' => "Создать класс Bootstrap (реализует Webman\\Bootstrap).\n\nРекомендуется:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nПримечания:\n  - По умолчанию создаётся в app/bootstrap (регистр по существующей директории).\n  - С -p/--plugin создаётся в plugin/<plugin>/app/bootstrap.\n  - С -P/--path создаётся в указанной относительной директории (от корня проекта).\n  - Позиционный аргумент enable управляет записью в config/bootstrap.php (по умолчанию включено; no/false/0/off для отключения).\n  - Если файл существует, запрашивается подтверждение перезаписи; -f/--force перезаписывает сразу.",
            'vi' => "Tạo lớp Bootstrap (triển khai Webman\\Bootstrap).\n\nKhuyến nghị:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nLưu ý:\n  - Mặc định tạo trong app/bootstrap (chữ hoa/thường theo thư mục hiện có).\n  - Với -p/--plugin tạo trong plugin/<plugin>/app/bootstrap.\n  - Với -P/--path tạo trong thư mục tương đối chỉ định (so với thư mục gốc dự án).\n  - Tham số vị trí enable điều khiển ghi vào config/bootstrap.php (mặc định bật; no/false/0/off để tắt).\n  - Nếu tệp đã tồn tại sẽ hỏi trước khi ghi đè; -f/--force ghi đè trực tiếp.",
            'tr' => "Bootstrap sınıfı oluştur (Webman\\Bootstrap uygular).\n\nÖnerilen:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nNotlar:\n  - Varsayılan olarak app/bootstrap altında oluşturulur (büyük/küçük harf mevcut dizine göre).\n  - -p/--plugin ile plugin/<plugin>/app/bootstrap altında oluşturulur.\n  - -P/--path ile belirtilen göreli dizinde oluşturulur (proje köküne göre).\n  - Konum argümanı enable, config/bootstrap.php'ye yazılmasını kontrol eder (varsayılan açık; no/false/0/off ile kapatma).\n  - Dosya varsa üzerine yazmadan önce sorar; -f/--force doğrudan üzerine yazar.",
            'id' => "Buat kelas Bootstrap (mengimplementasikan Webman\\Bootstrap).\n\nDirekomendasikan:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nCatatan:\n  - Secara default dibuat di app/bootstrap (huruf mengikuti direktori yang ada).\n  - Dengan -p/--plugin dibuat di plugin/<plugin>/app/bootstrap.\n  - Dengan -P/--path dibuat di direktori relatif yang ditentukan (terhadap akar proyek).\n  - Argumen posisi enable mengontrol penulisan ke config/bootstrap.php (default aktif; no/false/0/off untuk nonaktif).\n  - Jika file sudah ada akan ditanya sebelum menimpa; -f/--force menimpa langsung.",
            'th' => "สร้างคลาส Bootstrap (ใช้ Webman\\Bootstrap)\n\nแนะนำ:\n  php webman make:bootstrap MyBootstrap\n  php webman make:bootstrap MyBootstrap no\n  php webman make:bootstrap MyBootstrap -p admin\n  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap\n  php webman make:bootstrap MyBootstrap -f\n\nหมายเหตุ:\n  - ค่าเริ่มต้นสร้างใต้ app/bootstrap (ตัวพิมพ์ตามไดเรกทอรีที่มีอยู่)\n  - ใช้ -p/--plugin สร้างใต้ plugin/<plugin>/app/bootstrap\n  - ใช้ -P/--path สร้างในไดเรกทอรีสัมพัทธ์ที่ระบุ (เทียบกับรากโปรเจกต์)\n  - อาร์กิวเมนต์ enable ควบคุมการเขียน config/bootstrap.php (ค่าเริ่มต้นเปิด; no/false/0/off เพื่อปิด)\n  - ถ้ามีไฟล์อยู่แล้วจะถามก่อนเขียนทับ -f/--force เขียนทับโดยตรง",
        ]);
    }
}
