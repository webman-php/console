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

#[AsCommand('make:middleware', 'Make middleware')]
class MakeMiddlewareCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Middleware name');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/middleware');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        $this->setHelp($this->buildHelpText());

        $this->addUsage('Auth');
        $this->addUsage('Auth -p admin');
        $this->addUsage('Auth -P plugin/admin/app/middleware');
        $this->addUsage('Api/Auth -f');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = Util::nameToClass((string)$input->getArgument('name'));
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
                fn(string $p) => $this->getPluginMiddlewareRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$class, $namespace, $file] = $resolved;
        } else {
            [$class, $namespace, $file] = $this->resolveAppMiddlewareTarget($name);
        }

        $output->writeln($this->msg('make_middleware', ['{name}' => $class]));

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        $this->createMiddleware($class, $namespace, $file);
        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));

        $middlewareClass = "{$namespace}\\{$class}";
        $configFile = $plugin
            ? base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'middleware.php')
            : (config_path() . '/middleware.php');
        $changed = $this->addClassToMiddlewareConfig($configFile, $middlewareClass);
        if ($changed) {
            $output->writeln($this->msg('configured', ['{class}' => $middlewareClass, '{file}' => $this->toRelativePath($configFile)]));
        } else {
            $output->writeln($this->msg('configured_exists', ['{class}' => $middlewareClass, '{file}' => $this->toRelativePath($configFile)]));
        }

        return self::SUCCESS;
    }

    /**
     * Resolve middleware namespace/file path under app/ (backward compatible).
     *
     * @param string $name
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    protected function resolveAppMiddlewareTarget(string $name): array
    {
        $middlewareStr = Util::guessPath(app_path(), 'middleware');
        if (!$middlewareStr) {
            $middlewareStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Middleware' : 'middleware';
        }
        $upper = $middlewareStr === 'Middleware';

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $file = app_path() . DIRECTORY_SEPARATOR . $middlewareStr . DIRECTORY_SEPARATOR . "{$class}.php";
            $namespace = $upper ? 'App\Middleware' : 'app\middleware';
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

        $path = "{$middlewareStr}/{$dirPart}";
        $class = ucfirst(substr($name, $pos + 1));
        $file = app_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginMiddlewareRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $middlewareDir = Util::guessPath($appDir, 'middleware');
        if (!$middlewareDir) {
            $middlewareDir = Util::guessPath($appDir, 'controller') === 'Controller' ? 'Middleware' : 'middleware';
        }
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$middlewareDir}");
    }


    /**
     * @param $name
     * @param $namespace
     * @param $path
     * @return void
     */
    protected function createMiddleware($name, $namespace, $file)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $middleware_content = <<<EOF
<?php
namespace $namespace;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class $name implements MiddlewareInterface
{
    public function process(Request \$request, callable \$handler) : Response
    {
        return \$handler(\$request);
    }
    
}

EOF;
        file_put_contents($file, $middleware_content);
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
            'make_middleware' => '<info>创建中间件</info> <comment>{name}</comment>',
            'created' => '<info>已创建：</info> {path}',
            'configured' => '<info>已配置：</info> {class} -> {file}',
            'configured_exists' => '<comment>[Info]</comment> 配置已存在，跳过：{class} -> {file}',
            'override_prompt' => "<question>文件已存在：{path}</question>\n<question>是否覆盖？[Y/n]（回车=Y）</question>\n",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
        ];

        $en = [
            'make_middleware' => '<info>Make middleware</info> <comment>{name}</comment>',
            'created' => '<info>Created:</info> {path}',
            'configured' => '<info>Configured:</info> {class} -> {file}',
            'configured_exists' => '<comment>[Info]</comment> Already configured, skipped: {class} -> {file}',
            'override_prompt' => "<question>File already exists: {path}</question>\n<question>Override? [Y/n] (Enter = Y)</question>\n",
            'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
            'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
        ];

        $messages = [
            'zh_CN' => $zh, 'zh_TW' => [
                'make_middleware' => '<info>建立中間件</info> <comment>{name}</comment>',
                'created' => '<info>已建立：</info> {path}',
                'configured' => '<info>已設定：</info> {class} -> {file}',
                'configured_exists' => '<comment>[Info]</comment> 設定已存在，略過：{class} -> {file}',
                'override_prompt' => "<question>檔案已存在：{path}</question>\n<question>是否覆蓋？[Y/n]（Enter=Y）</question>\n",
                'invalid_plugin' => '<error>插件名稱無效：{plugin}。`--plugin/-p` 只能是 plugin/ 目錄下的目錄名，不能包含 / 或 \\。</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` 與 `--path/-P` 同時指定且不一致。\n期望路徑：{expected}\n實際路徑：{actual}\n請二選一或保持一致。</error>",
                'invalid_path' => '<error>路徑無效：{path}。`--path/-P` 必須是相對路徑（相對於專案根目錄），不能是絕對路徑。</error>',
            ],
            'en' => $en,
            'ja' => [
                'make_middleware' => '<info>ミドルウェアを作成</info> <comment>{name}</comment>',
                'created' => '<info>作成しました：</info> {path}',
                'configured' => '<info>設定しました：</info> {class} -> {file}',
                'configured_exists' => '<comment>[Info]</comment> 既に設定済み、スキップ：{class} -> {file}',
                'override_prompt' => "<question>ファイルが既に存在します：{path}</question>\n<question>上書きしますか？[Y/n]（Enter=Y）</question>\n",
                'invalid_plugin' => '<error>プラグイン名が無効です：{plugin}。`--plugin/-p` は plugin/ 以下のディレクトリ名で、/ または \\ を含めません。</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` と `--path/-P` が両方指定されていますが一致しません。\n期待：{expected}\n実際：{actual}\nどちらか一方に揃えてください。</error>",
                'invalid_path' => '<error>パスが無効です：{path}。`--path/-P` はプロジェクトルートからの相対パスで、絶対パスは不可です。</error>',
            ],
            'ko' => ['make_middleware' => '<info>미들웨어 만들기</info> <comment>{name}</comment>', 'created' => '<info>생성됨:</info> {path}', 'configured' => '<info>설정됨:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> 이미 설정됨, 건너뜀: {class} -> {file}', 'override_prompt' => "<question>파일이 이미 있습니다: {path}</question>\n<question>덮어쓸까요? [Y/n] (Enter=Y)</question>\n", 'invalid_plugin' => '<error>잘못된 플러그인 이름: {plugin}. `--plugin/-p`는 plugin/ 아래 디렉터리 이름이며 / 또는 \\를 포함할 수 없습니다.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p`와 `--path/-P`가 모두 지정되었지만 일치하지 않습니다.\n예상: {expected}\n실제: {actual}\n하나만 사용하거나 동일하게 맞추세요.</error>", 'invalid_path' => '<error>잘못된 경로: {path}. `--path/-P`는 프로젝트 루트 기준 상대 경로여야 하며 절대 경로는 안 됩니다.</error>'],
            'fr' => ['make_middleware' => '<info>Créer un middleware</info> <comment>{name}</comment>', 'created' => '<info>Créé :</info> {path}', 'configured' => '<info>Configuré :</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Déjà configuré, ignoré : {class} -> {file}', 'override_prompt' => "<question>Le fichier existe déjà : {path}</question>\n<question>Écraser ? [Y/n] (Entrée = Y)</question>\n", 'invalid_plugin' => '<error>Nom de plugin invalide : {plugin}. `--plugin/-p` doit être un nom de dossier sous plugin/, sans / ni \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` et `--path/-P` sont tous deux fournis mais incohérents.\nAttendu : {expected}\nRéel : {actual}\nN\'en fournissez qu\'un ou rendez-les identiques.</error>", 'invalid_path' => '<error>Chemin invalide : {path}. `--path/-P` doit être un chemin relatif (à la racine du projet), pas absolu.</error>'],
            'de' => ['make_middleware' => '<info>Middleware erstellen</info> <comment>{name}</comment>', 'created' => '<info>Erstellt:</info> {path}', 'configured' => '<info>Konfiguriert:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Bereits konfiguriert, übersprungen: {class} -> {file}', 'override_prompt' => "<question>Datei existiert bereits: {path}</question>\n<question>Überschreiben? [Y/n] (Eingabe = Y)</question>\n", 'invalid_plugin' => '<error>Ungültiger Plugin-Name: {plugin}. `--plugin/-p` muss ein Ordnername unter plugin/ sein, ohne / oder \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` und `--path/-P` sind beide angegeben, aber nicht konsistent.\nErwartet: {expected}\nTatsächlich: {actual}\nNur eines angeben oder angleichen.</error>", 'invalid_path' => '<error>Ungültiger Pfad: {path}. `--path/-P` muss ein relativer Pfad (zur Projektwurzel) sein, kein absoluter.</error>'],
            'es' => ['make_middleware' => '<info>Crear middleware</info> <comment>{name}</comment>', 'created' => '<info>Creado:</info> {path}', 'configured' => '<info>Configurado:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Ya configurado, omitido: {class} -> {file}', 'override_prompt' => "<question>El archivo ya existe: {path}</question>\n<question>¿Sobrescribir? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Nombre de plugin no válido: {plugin}. `--plugin/-p` debe ser un nombre de carpeta bajo plugin/, sin / ni \\.</error>', 'plugin_path_conflict' => "<error>Se dieron `--plugin/-p` y `--path/-P` pero no coinciden.\nEsperado: {expected}\nReal: {actual}\nProporcione solo uno o hágalos idénticos.</error>", 'invalid_path' => '<error>Ruta no válida: {path}. `--path/-P` debe ser una ruta relativa (a la raíz del proyecto), no absoluta.</error>'],
            'pt_BR' => ['make_middleware' => '<info>Criar middleware</info> <comment>{name}</comment>', 'created' => '<info>Criado:</info> {path}', 'configured' => '<info>Configurado:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Já configurado, ignorado: {class} -> {file}', 'override_prompt' => "<question>O arquivo já existe: {path}</question>\n<question>Sobrescrever? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Nome de plugin inválido: {plugin}. `--plugin/-p` deve ser um nome de pasta em plugin/, sem / ou \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` e `--path/-P` foram fornecidos mas são inconsistentes.\nEsperado: {expected}\nAtual: {actual}\nForneça apenas um ou deixe-os iguais.</error>", 'invalid_path' => '<error>Caminho inválido: {path}. `--path/-P` deve ser um caminho relativo (à raiz do projeto), não absoluto.</error>'],
            'ru' => ['make_middleware' => '<info>Создать middleware</info> <comment>{name}</comment>', 'created' => '<info>Создано:</info> {path}', 'configured' => '<info>Настроено:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Уже настроено, пропущено: {class} -> {file}', 'override_prompt' => "<question>Файл уже существует: {path}</question>\n<question>Перезаписать? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Недопустимое имя плагина: {plugin}. `--plugin/-p` должно быть именем каталога в plugin/, без / или \\.</error>', 'plugin_path_conflict' => "<error>Указаны и `--plugin/-p`, и `--path/-P`, но они не совпадают.\nОжидалось: {expected}\nФактически: {actual}\nУкажите что-то одно или сделайте их одинаковыми.</error>", 'invalid_path' => '<error>Недопустимый путь: {path}. `--path/-P` должен быть относительным путём (к корню проекта), не абсолютным.</error>'],
            'vi' => ['make_middleware' => '<info>Tạo middleware</info> <comment>{name}</comment>', 'created' => '<info>Đã tạo:</info> {path}', 'configured' => '<info>Đã cấu hình:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Đã cấu hình, bỏ qua: {class} -> {file}', 'override_prompt' => "<question>Tệp đã tồn tại: {path}</question>\n<question>Ghi đè? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Tên plugin không hợp lệ: {plugin}. `--plugin/-p` phải là tên thư mục trong plugin/, không chứa / hoặc \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` và `--path/-P` đều được chỉ định nhưng không khớp.\nMong đợi: {expected}\nThực tế: {actual}\nChỉ dùng một hoặc cho chúng giống nhau.</error>", 'invalid_path' => '<error>Đường dẫn không hợp lệ: {path}. `--path/-P` phải là đường dẫn tương đối (tới thư mục gốc dự án), không phải tuyệt đối.</error>'],
            'tr' => ['make_middleware' => '<info>Middleware oluştur</info> <comment>{name}</comment>', 'created' => '<info>Oluşturuldu:</info> {path}', 'configured' => '<info>Yapılandırıldı:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Zaten yapılandırılmış, atlandı: {class} -> {file}', 'override_prompt' => "<question>Dosya zaten mevcut: {path}</question>\n<question>Üzerine yazılsın mı? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Geçersiz eklenti adı: {plugin}. `--plugin/-p` plugin/ altında bir klasör adı olmalı, / veya \\ içermemeli.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` ve `--path/-P` birlikte verilmiş ancak uyuşmuyor.\nBeklenen: {expected}\nGerçek: {actual}\nYalnızca birini verin veya aynı yapın.</error>", 'invalid_path' => '<error>Geçersiz yol: {path}. `--path/-P` proje köküne göre göreli yol olmalı, mutlak yol olmamalı.</error>'],
            'id' => ['make_middleware' => '<info>Buat middleware</info> <comment>{name}</comment>', 'created' => '<info>Dibuat:</info> {path}', 'configured' => '<info>Dikonfigurasi:</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> Sudah dikonfigurasi, dilewati: {class} -> {file}', 'override_prompt' => "<question>File sudah ada: {path}</question>\n<question>Timpa? [Y/n] (Enter = Y)</question>\n", 'invalid_plugin' => '<error>Nama plugin tidak valid: {plugin}. `--plugin/-p` harus nama folder di bawah plugin/, tidak boleh mengandung / atau \\.</error>', 'plugin_path_conflict' => "<error>`--plugin/-p` dan `--path/-P` keduanya diberikan tetapi tidak konsisten.\nHarapan: {expected}\nActual: {actual}\nBerikan hanya satu atau samakan.</error>", 'invalid_path' => '<error>Path tidak valid: {path}. `--path/-P` harus path relatif (ke akar proyek), bukan absolut.</error>'],
            'th' => ['make_middleware' => '<info>สร้างมิดเดิลแวร์</info> <comment>{name}</comment>', 'created' => '<info>สร้างแล้ว：</info> {path}', 'configured' => '<info>ตั้งค่าแล้ว：</info> {class} -> {file}', 'configured_exists' => '<comment>[Info]</comment> ตั้งค่าแล้ว ข้าม：{class} -> {file}', 'override_prompt' => "<question>มีไฟล์อยู่แล้ว：{path}</question>\n<question>เขียนทับ？[Y/n]（Enter=Y）</question>\n", 'invalid_plugin' => '<error>ชื่อปลั๊กอินไม่ถูกต้อง：{plugin}。`--plugin/-p` ต้องเป็นชื่อโฟลเดอร์ภายใต้ plugin/ ห้ามมี / หรือ \\</error>', 'plugin_path_conflict' => "<error>ระบุทั้ง `--plugin/-p` และ `--path/-P` แต่ไม่ตรงกัน\nคาดว่า：{expected}\nจริง：{actual}\nใช้อย่างใดอย่างหนึ่งหรือให้ตรงกัน</error>", 'invalid_path' => '<error>เส้นทางไม่ถูกต้อง：{path}。`--path/-P` ต้องเป็นเส้นทางสัมพัทธ์（จากรากโปรเจกต์）ไม่ใช่แบบสัมบูรณ์</error>'],
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
        $en = "Generate a middleware file.\n\nRecommended:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nNotes:\n  - By default, it generates under app/middleware (case depends on existing directory).\n  - With -p/--plugin, it generates under plugin/<plugin>/app/middleware by default.\n  - With -P/--path, it generates under the specified relative directory (to project root).\n  - If the file already exists, it will ask before overriding; use -f/--force to override directly.";
        return Util::selectByLocale([
            'zh_CN' => "生成中间件文件。\n\n推荐用法：\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\n说明：\n  - 默认生成到 app/middleware（大小写以现有目录为准）。\n  - 使用 -p/--plugin 时默认生成到 plugin/<plugin>/app/middleware。\n  - 使用 -P/--path 时生成到指定相对目录（相对于项目根目录）。\n  - 文件已存在时默认会提示是否覆盖；使用 -f/--force 可直接覆盖。",
            'zh_TW' => "建立中間件檔案。\n\n推薦用法：\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\n說明：\n  - 預設生成到 app/middleware（大小寫依現有目錄）。\n  - 使用 -p/--plugin 時預設生成到 plugin/<plugin>/app/middleware。\n  - 使用 -P/--path 時生成到指定相對目錄（相對於專案根目錄）。\n  - 檔案已存在時會詢問是否覆蓋；使用 -f/--force 可直接覆蓋。",
            'en' => $en,
            'ja' => "ミドルウェアファイルを生成。\n\n推奨：\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\n説明：\n  - デフォルトは app/middleware に生成（大文字小文字は既存ディレクトリに合わせる）。\n  - -p/--plugin の場合は plugin/<plugin>/app/middleware に生成。\n  - -P/--path で相対ディレクトリを指定可能（プロジェクトルート基準）。\n  - ファイルが既にある場合は上書き確認；-f/--force で直接上書き。",
            'ko' => "미들웨어 파일 생성.\n\n권장:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\n참고:\n  - 기본 생성 위치 app/middleware(기존 디렉터리 대소문자 따름).\n  - -p/--plugin 사용 시 plugin/<plugin>/app/middleware에 생성.\n  - -P/--path로 프로젝트 루트 기준 상대 경로 지정 가능.\n  - 파일이 있으면 덮어쓸지 묻고, -f/--force로 직접 덮어쓰기.",
            'fr' => "Générer un fichier middleware.\n\nRecommandé :\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nNotes :\n  - Par défaut, génération sous app/middleware (casse selon le répertoire existant).\n  - Avec -p/--plugin, génération sous plugin/<plugin>/app/middleware.\n  - Avec -P/--path, génération dans le répertoire relatif indiqué (par rapport à la racine).\n  - Si le fichier existe, demande avant d'écraser ; -f/--force pour écraser directement.",
            'de' => "Middleware-Datei erzeugen.\n\nEmpfohlen:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nHinweise:\n  - Standard: Erzeugung unter app/middleware (Groß-/Kleinschreibung nach vorhandenem Verzeichnis).\n  - Mit -p/--plugin: unter plugin/<plugin>/app/middleware.\n  - Mit -P/--path: unter angegebenem relativem Pfad (zur Projektwurzel).\n  - Bei vorhandener Datei wird gefragt; -f/--force überschreibt direkt.",
            'es' => "Generar un archivo de middleware.\n\nRecomendado:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nNotas:\n  - Por defecto se genera en app/middleware (mayúsculas según el directorio existente).\n  - Con -p/--plugin se genera en plugin/<plugin>/app/middleware.\n  - Con -P/--path se genera en el directorio relativo indicado (respecto a la raíz).\n  - Si el archivo existe, pregunta antes de sobrescribir; -f/--force sobrescribe directamente.",
            'pt_BR' => "Gerar um arquivo de middleware.\n\nRecomendado:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nNotas:\n  - Por padrão gera em app/middleware (maiúsculas conforme o diretório existente).\n  - Com -p/--plugin gera em plugin/<plugin>/app/middleware.\n  - Com -P/--path gera no diretório relativo indicado (em relação à raiz do projeto).\n  - Se o arquivo existir, pergunta antes de sobrescrever; -f/--force sobrescreve diretamente.",
            'ru' => "Создать файл middleware.\n\nРекомендуется:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nПримечания:\n  - По умолчанию создаётся в app/middleware (регистр по существующей директории).\n  - С -p/--plugin создаётся в plugin/<plugin>/app/middleware.\n  - С -P/--path создаётся в указанной относительной директории (от корня проекта).\n  - Если файл существует, запрашивается подтверждение перезаписи; -f/--force перезаписывает сразу.",
            'vi' => "Tạo tệp middleware.\n\nKhuyến nghị:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nLưu ý:\n  - Mặc định tạo trong app/middleware (chữ hoa/thường theo thư mục hiện có).\n  - Với -p/--plugin tạo trong plugin/<plugin>/app/middleware.\n  - Với -P/--path tạo trong thư mục tương đối chỉ định (so với thư mục gốc dự án).\n  - Nếu tệp đã tồn tại sẽ hỏi trước khi ghi đè; -f/--force ghi đè trực tiếp.",
            'tr' => "Middleware dosyası oluştur.\n\nÖnerilen:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nNotlar:\n  - Varsayılan olarak app/middleware altında oluşturulur (büyük/küçük harf mevcut dizine göre).\n  - -p/--plugin ile plugin/<plugin>/app/middleware altında oluşturulur.\n  - -P/--path ile belirtilen göreli dizinde oluşturulur (proje köküne göre).\n  - Dosya varsa üzerine yazmadan önce sorar; -f/--force doğrudan üzerine yazar.",
            'id' => "Buat file middleware.\n\nDirekomendasikan:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nCatatan:\n  - Secara default dibuat di app/middleware (huruf mengikuti direktori yang ada).\n  - Dengan -p/--plugin dibuat di plugin/<plugin>/app/middleware.\n  - Dengan -P/--path dibuat di direktori relatif yang ditentukan (terhadap akar proyek).\n  - Jika file sudah ada akan ditanya sebelum menimpa; -f/--force menimpa langsung.",
            'th' => "สร้างไฟล์มิดเดิลแวร์\n\nแนะนำ:\n  php webman make:middleware Auth\n  php webman make:middleware Auth -p admin\n  php webman make:middleware Auth -P plugin/admin/app/middleware\n  php webman make:middleware Api/Auth -f\n\nหมายเหตุ:\n  - ค่าเริ่มต้นสร้างใต้ app/middleware (ตัวพิมพ์ตามไดเรกทอรีที่มีอยู่)\n  - ใช้ -p/--plugin สร้างใต้ plugin/<plugin>/app/middleware\n  - ใช้ -P/--path สร้างในไดเรกทอรีสัมพัทธ์ที่ระบุ (เทียบกับรากโปรเจกต์)\n  - ถ้ามีไฟล์อยู่แล้วจะถามก่อนเขียนทับ -f/--force เขียนทับโดยตรง",
        ]);
    }

}
