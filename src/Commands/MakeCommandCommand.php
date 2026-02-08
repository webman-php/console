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

#[AsCommand('make:command', 'Make command')]
class MakeCommandCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Command name');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/command');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        $this->setHelp($this->buildHelpText());

        $this->addUsage('user:list');
        $this->addUsage('user:list -p admin');
        $this->addUsage('user:list -P plugin/admin/app/command');
        $this->addUsage('user:list -f');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $command = trim((string)$input->getArgument('name'));
        $plugin = $this->normalizeOptionValue($input->getOption('plugin'));
        $path = $this->normalizeOptionValue($input->getOption('path'));
        $force = (bool)$input->getOption('force');

        if ($plugin && (str_contains($plugin, '/') || str_contains($plugin, '\\'))) {
            $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $plugin]));
            return Command::FAILURE;
        }

        // make:command 不支持子目录（不允许 / 或 \）
        $command = str_replace(['\\', '/'], '', $command);
        if ($command === '') {
            $output->writeln($this->msg('invalid_command'));
            return Command::FAILURE;
        }

        $class = $this->commandToClassName($command);

        if ($plugin || $path) {
            $resolved = $this->resolveTargetByPluginOrPath(
                $class,
                $plugin,
                $path,
                $output,
                fn(string $p) => $this->getPluginCommandRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$class, $namespace, $file] = $resolved;
        } else {
            [$class, $namespace, $file] = $this->resolveAppCommandTarget($class);
        }

        $output->writeln($this->msg('make_command', ['{name}' => $command]));

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        $this->createCommand($class, $namespace, $file, $command);
        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));

        return self::SUCCESS;
    }

    /**
     * Convert a console command name (like "user:list") to a PHP class name (like "UserList").
     *
     * @param string $command
     * @return string
     */
    protected function commandToClassName(string $command): string
    {
        $items = array_values(array_filter(explode(':', $command), static fn($v) => $v !== ''));
        $name = '';
        foreach ($items as $item) {
            // Support kebab/snake: foo-bar => FooBar
            $tmp = Util::nameToClass(str_replace('-', '_', $item));
            $tmp = str_replace(['\\', '/'], '', $tmp);
            $name .= ucfirst($tmp);
        }
        return $name ?: 'ConsoleCommand';
    }

    /**
     * Resolve command namespace/file path under app/ (backward compatible).
     *
     * @param string $class
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    protected function resolveAppCommandTarget(string $class): array
    {
        $commandStr = Util::guessPath(app_path(), 'command');
        if (!$commandStr) {
            $commandStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Command' : 'command';
        }
        $upper = $commandStr === 'Command';
        $file = app_path() . DIRECTORY_SEPARATOR . $commandStr . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = $upper ? 'App\Command' : 'app\command';
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginCommandRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $commandDir = Util::guessPath($appDir, 'command');
        if (!$commandDir) {
            $commandDir = Util::guessPath($appDir, 'controller') === 'Controller' ? 'Command' : 'command';
        }
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$commandDir}");
    }

    /**
     * @param $name
     * @param $namespace
     * @param $path
     * @return void
     */
    protected function createCommand($name, $namespace, $file, $command)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $desc = str_replace(':', ' ', $command);
        $command_content = <<<EOF
<?php

namespace $namespace;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('$command', '$desc')]
class $name extends Command
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface \$input, OutputInterface \$output): int
    {
        \$output->writeln('<info>Hello</info> <comment>' . \$this->getName() . '</comment>');
        return self::SUCCESS;
    }
}

EOF;
        file_put_contents($file, $command_content);
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
        $messages = [
            'zh_CN' => [
                'make_command' => '<info>创建命令</info> <comment>{name}</comment>',
                'created' => '<info>已创建：</info> {path}',
                'override_prompt' => "<question>文件已存在：{path}</question>\n<question>是否覆盖？[Y/n]（回车=Y）</question>\n",
                'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
                'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
                'invalid_command' => '<error>命令名不能为空。</error>',
            ],
            'zh_TW' => [
                'make_command' => '<info>建立命令</info> <comment>{name}</comment>',
                'created' => '<info>已建立：</info> {path}',
                'override_prompt' => "<question>檔案已存在：{path}</question>\n<question>是否覆蓋？[Y/n]（Enter=Y）</question>\n",
                'invalid_plugin' => '<error>插件名稱無效：{plugin}。`--plugin/-p` 只能是 plugin/ 目錄下的目錄名，不能包含 / 或 \\。</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` 與 `--path/-P` 同時指定且不一致。\n期望路徑：{expected}\n實際路徑：{actual}\n請二選一或保持一致。</error>",
                'invalid_path' => '<error>路徑無效：{path}。`--path/-P` 必須是相對路徑（相對於專案根目錄），不能是絕對路徑。</error>',
                'invalid_command' => '<error>命令名稱不可為空。</error>',
            ],
            'en' => [
                'make_command' => '<info>Make command</info> <comment>{name}</comment>',
                'created' => '<info>Created:</info> {path}',
                'override_prompt' => "<question>File already exists: {path}</question>\n<question>Override? [Y/n] (Enter = Y)</question>\n",
                'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
                'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
                'invalid_command' => '<error>Command name cannot be empty.</error>',
            ],
            'ja' => [
                'make_command' => '<info>コマンドを作成</info> <comment>{name}</comment>',
                'created' => '<info>作成しました：</info> {path}',
                'override_prompt' => "<question>ファイルが既に存在します：{path}</question>\n<question>上書きしますか？[Y/n]（Enter=Y）</question>\n",
                'invalid_plugin' => '<error>プラグイン名が無効です：{plugin}。`--plugin/-p` は plugin/ 以下のディレクトリ名で、/ または \\ を含めません。</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` と `--path/-P` が両方指定されていますが一致しません。\n期待：{expected}\n実際：{actual}\nどちらか一方に揃えてください。</error>",
                'invalid_path' => '<error>パスが無効です：{path}。`--path/-P` はプロジェクトルートからの相対パスで、絶対パスは不可です。</error>',
                'invalid_command' => '<error>コマンド名を空にできません。</error>',
            ],
            'ko' => [
                'make_command' => '<info>명령 만들기</info> <comment>{name}</comment>',
                'created' => '<info>생성됨:</info> {path}',
                'override_prompt' => "<question>파일이 이미 있습니다: {path}</question>\n<question>덮어쓸까요? [Y/n] (Enter=Y)</question>\n",
                'invalid_plugin' => '<error>잘못된 플러그인 이름: {plugin}. `--plugin/-p`는 plugin/ 아래 디렉터리 이름이며 / 또는 \\를 포함할 수 없습니다.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p`와 `--path/-P`가 모두 지정되었지만 일치하지 않습니다.\n예상: {expected}\n실제: {actual}\n하나만 사용하거나 동일하게 맞추세요.</error>",
                'invalid_path' => '<error>잘못된 경로: {path}. `--path/-P`는 프로젝트 루트 기준 상대 경로여야 하며 절대 경로는 안 됩니다.</error>',
                'invalid_command' => '<error>명령 이름이 비어 있을 수 없습니다.</error>',
            ],
            'fr' => [
                'make_command' => '<info>Créer une commande</info> <comment>{name}</comment>',
                'created' => '<info>Créé :</info> {path}',
                'override_prompt' => "<question>Le fichier existe déjà : {path}</question>\n<question>Écraser ? [Y/n] (Entrée = Y)</question>\n",
                'invalid_plugin' => '<error>Nom de plugin invalide : {plugin}. `--plugin/-p` doit être un nom de dossier sous plugin/, sans / ni \\.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` et `--path/-P` sont tous deux fournis mais incohérents.\nAttendu : {expected}\nRéel : {actual}\nN\'en fournissez qu\'un ou rendez-les identiques.</error>",
                'invalid_path' => '<error>Chemin invalide : {path}. `--path/-P` doit être un chemin relatif (à la racine du projet), pas absolu.</error>',
                'invalid_command' => '<error>Le nom de la commande ne peut pas être vide.</error>',
            ],
            'de' => [
                'make_command' => '<info>Befehl erstellen</info> <comment>{name}</comment>',
                'created' => '<info>Erstellt:</info> {path}',
                'override_prompt' => "<question>Datei existiert bereits: {path}</question>\n<question>Überschreiben? [Y/n] (Eingabe = Y)</question>\n",
                'invalid_plugin' => '<error>Ungültiger Plugin-Name: {plugin}. `--plugin/-p` muss ein Ordnername unter plugin/ sein, ohne / oder \\.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` und `--path/-P` sind beide angegeben, aber nicht konsistent.\nErwartet: {expected}\nTatsächlich: {actual}\nNur eines angeben oder angleichen.</error>",
                'invalid_path' => '<error>Ungültiger Pfad: {path}. `--path/-P` muss ein relativer Pfad (zur Projektwurzel) sein, kein absoluter.</error>',
                'invalid_command' => '<error>Der Befehlsname darf nicht leer sein.</error>',
            ],
            'es' => [
                'make_command' => '<info>Crear comando</info> <comment>{name}</comment>',
                'created' => '<info>Creado:</info> {path}',
                'override_prompt' => "<question>El archivo ya existe: {path}</question>\n<question>¿Sobrescribir? [Y/n] (Enter = Y)</question>\n",
                'invalid_plugin' => '<error>Nombre de plugin no válido: {plugin}. `--plugin/-p` debe ser un nombre de carpeta bajo plugin/, sin / ni \\.</error>',
                'plugin_path_conflict' => "<error>Se dieron `--plugin/-p` y `--path/-P` pero no coinciden.\nEsperado: {expected}\nReal: {actual}\nProporcione solo uno o hágalos idénticos.</error>",
                'invalid_path' => '<error>Ruta no válida: {path}. `--path/-P` debe ser una ruta relativa (a la raíz del proyecto), no absoluta.</error>',
                'invalid_command' => '<error>El nombre del comando no puede estar vacío.</error>',
            ],
            'pt_BR' => [
                'make_command' => '<info>Criar comando</info> <comment>{name}</comment>',
                'created' => '<info>Criado:</info> {path}',
                'override_prompt' => "<question>O arquivo já existe: {path}</question>\n<question>Sobrescrever? [Y/n] (Enter = Y)</question>\n",
                'invalid_plugin' => '<error>Nome de plugin inválido: {plugin}. `--plugin/-p` deve ser um nome de pasta em plugin/, sem / ou \\.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` e `--path/-P` foram fornecidos mas são inconsistentes.\nEsperado: {expected}\nAtual: {actual}\nForneça apenas um ou deixe-os iguais.</error>",
                'invalid_path' => '<error>Caminho inválido: {path}. `--path/-P` deve ser um caminho relativo (à raiz do projeto), não absoluto.</error>',
                'invalid_command' => '<error>O nome do comando não pode estar vazio.</error>',
            ],
            'ru' => [
                'make_command' => '<info>Создать команду</info> <comment>{name}</comment>',
                'created' => '<info>Создано:</info> {path}',
                'override_prompt' => "<question>Файл уже существует: {path}</question>\n<question>Перезаписать? [Y/n] (Enter = Y)</question>\n",
                'invalid_plugin' => '<error>Недопустимое имя плагина: {plugin}. `--plugin/-p` должно быть именем каталога в plugin/, без / или \\.</error>',
                'plugin_path_conflict' => "<error>Указаны и `--plugin/-p`, и `--path/-P`, но они не совпадают.\nОжидалось: {expected}\nФактически: {actual}\nУкажите что-то одно или сделайте их одинаковыми.</error>",
                'invalid_path' => '<error>Недопустимый путь: {path}. `--path/-P` должен быть относительным путём (к корню проекта), не абсолютным.</error>',
                'invalid_command' => '<error>Имя команды не может быть пустым.</error>',
            ],
            'vi' => [
                'make_command' => '<info>Tạo lệnh</info> <comment>{name}</comment>',
                'created' => '<info>Đã tạo:</info> {path}',
                'override_prompt' => "<question>Tệp đã tồn tại: {path}</question>\n<question>Ghi đè? [Y/n] (Enter = Y)</question>\n",
                'invalid_plugin' => '<error>Tên plugin không hợp lệ: {plugin}. `--plugin/-p` phải là tên thư mục trong plugin/, không chứa / hoặc \\.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` và `--path/-P` đều được chỉ định nhưng không khớp.\nMong đợi: {expected}\nThực tế: {actual}\nChỉ dùng một hoặc cho chúng giống nhau.</error>",
                'invalid_path' => '<error>Đường dẫn không hợp lệ: {path}. `--path/-P` phải là đường dẫn tương đối (tới thư mục gốc dự án), không phải tuyệt đối.</error>',
                'invalid_command' => '<error>Tên lệnh không được để trống.</error>',
            ],
            'tr' => [
                'make_command' => '<info>Komut oluştur</info> <comment>{name}</comment>',
                'created' => '<info>Oluşturuldu:</info> {path}',
                'override_prompt' => "<question>Dosya zaten mevcut: {path}</question>\n<question>Üzerine yazılsın mı? [Y/n] (Enter = Y)</question>\n",
                'invalid_plugin' => '<error>Geçersiz eklenti adı: {plugin}. `--plugin/-p` plugin/ altında bir klasör adı olmalı, / veya \\ içermemeli.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` ve `--path/-P` birlikte verilmiş ancak uyuşmuyor.\nBeklenen: {expected}\nGerçek: {actual}\nYalnızca birini verin veya aynı yapın.</error>",
                'invalid_path' => '<error>Geçersiz yol: {path}. `--path/-P` proje köküne göre göreli yol olmalı, mutlak yol olmamalı.</error>',
                'invalid_command' => '<error>Komut adı boş olamaz.</error>',
            ],
            'id' => [
                'make_command' => '<info>Buat perintah</info> <comment>{name}</comment>',
                'created' => '<info>Dibuat:</info> {path}',
                'override_prompt' => "<question>File sudah ada: {path}</question>\n<question>Timpa? [Y/n] (Enter = Y)</question>\n",
                'invalid_plugin' => '<error>Nama plugin tidak valid: {plugin}. `--plugin/-p` harus nama folder di bawah plugin/, tidak boleh mengandung / atau \\.</error>',
                'plugin_path_conflict' => "<error>`--plugin/-p` dan `--path/-P` keduanya diberikan tetapi tidak konsisten.\nHarapan: {expected}\nActual: {actual}\nBerikan hanya satu atau samakan.</error>",
                'invalid_path' => '<error>Path tidak valid: {path}. `--path/-P` harus path relatif (ke akar proyek), bukan absolut.</error>',
                'invalid_command' => '<error>Nama perintah tidak boleh kosong.</error>',
            ],
            'th' => [
                'make_command' => '<info>สร้างคำสั่ง</info> <comment>{name}</comment>',
                'created' => '<info>สร้างแล้ว：</info> {path}',
                'override_prompt' => "<question>มีไฟล์อยู่แล้ว：{path}</question>\n<question>เขียนทับ？[Y/n]（Enter=Y）</question>\n",
                'invalid_plugin' => '<error>ชื่อปลั๊กอินไม่ถูกต้อง：{plugin}。`--plugin/-p` ต้องเป็นชื่อโฟลเดอร์ภายใต้ plugin/ ห้ามมี / หรือ \\</error>',
                'plugin_path_conflict' => "<error>ระบุทั้ง `--plugin/-p` และ `--path/-P` แต่ไม่ตรงกัน\nคาดว่า：{expected}\nจริง：{actual}\nใช้อย่างใดอย่างหนึ่งหรือให้ตรงกัน</error>",
                'invalid_path' => '<error>เส้นทางไม่ถูกต้อง：{path}。`--path/-P` ต้องเป็นเส้นทางสัมพัทธ์（จากรากโปรเจกต์）ไม่ใช่แบบสัมบูรณ์</error>',
                'invalid_command' => '<error>ชื่อคำสั่งต้องไม่ว่าง</error>',
            ],
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
        $helps = [
            'zh_CN' => "生成 Console 命令类（Symfony Console）。\n\n推荐用法：\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\n说明：\n  - 命令名支持冒号分段（例如 user:list），生成类名会自动转为驼峰（UserList）。\n  - 默认生成到 app/command（大小写以现有目录为准）。\n  - 使用 -p/--plugin 时默认生成到 plugin/<plugin>/app/command。\n  - 使用 -P/--path 时生成到指定相对目录（相对于项目根目录）。\n  - 文件已存在时默认会提示是否覆盖；使用 -f/--force 可直接覆盖。",
            'zh_TW' => "建立 Console 命令類（Symfony Console）。\n\n推薦用法：\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\n說明：\n  - 命令名支援冒號分段（如 user:list），類名會自動轉為駝峰（UserList）。\n  - 預設生成到 app/command（大小寫依現有目錄）。\n  - 使用 -p/--plugin 時預設生成到 plugin/<plugin>/app/command。\n  - 使用 -P/--path 時生成到指定相對目錄（相對於專案根目錄）。\n  - 檔案已存在時會詢問是否覆蓋；使用 -f/--force 可直接覆蓋。",
            'en' => "Generate a Console command class (Symfony Console).\n\nRecommended:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nNotes:\n  - Command name supports colon segments (e.g. user:list). The class name will be camel-cased (UserList).\n  - By default, it generates under app/command (case depends on existing directory).\n  - With -p/--plugin, it generates under plugin/<plugin>/app/command by default.\n  - With -P/--path, it generates under the specified relative directory (to project root).\n  - If the file already exists, it will ask before overriding; use -f/--force to override directly.",
            'ja' => "Console コマンドクラスを生成（Symfony Console）。\n\n推奨：\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\n説明：\n  - コマンド名はコロン区切り（例 user:list）に対応。クラス名はキャメルケース（UserList）になります。\n  - デフォルトは app/command に生成（大文字小文字は既存ディレクトリに合わせる）。\n  - -p/--plugin の場合は plugin/<plugin>/app/command に生成。\n  - -P/--path で相対ディレクトリを指定可能（プロジェクトルート基準）。\n  - ファイルが既にある場合は上書き確認；-f/--force で直接上書き。",
            'ko' => "Console 명령 클래스 생성 (Symfony Console).\n\n권장:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\n참고:\n  - 명령 이름은 콜론 구분(예: user:list) 지원. 클래스명은 캐멀케이스(UserList)로 생성.\n  - 기본 생성 위치 app/command(기존 디렉터리 대소문자 따름).\n  - -p/--plugin 사용 시 plugin/<plugin>/app/command에 생성.\n  - -P/--path로 프로젝트 루트 기준 상대 경로 지정 가능.\n  - 파일이 있으면 덮어쓸지 묻고, -f/--force로 직접 덮어쓰기.",
            'fr' => "Générer une classe de commande Console (Symfony Console).\n\nRecommandé :\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nNotes :\n  - Le nom de commande peut contenir des deux-points (ex. user:list), le nom de classe sera en camelCase (UserList).\n  - Par défaut, génération sous app/command (casse selon le répertoire existant).\n  - Avec -p/--plugin, génération sous plugin/<plugin>/app/command.\n  - Avec -P/--path, génération dans le répertoire relatif indiqué (par rapport à la racine).\n  - Si le fichier existe, demande avant d'écraser ; -f/--force pour écraser directement.",
            'de' => "Eine Console-Befehlsklasse erzeugen (Symfony Console).\n\nEmpfohlen:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nHinweise:\n  - Befehlsname kann Doppelpunkte enthalten (z. B. user:list), Klassenname wird zu CamelCase (UserList).\n  - Standard: Erzeugung unter app/command (Groß-/Kleinschreibung nach vorhandenem Verzeichnis).\n  - Mit -p/--plugin: unter plugin/<plugin>/app/command.\n  - Mit -P/--path: unter angegebenem relativem Pfad (zur Projektwurzel).\n  - Bei vorhandener Datei wird gefragt; -f/--force überschreibt direkt.",
            'es' => "Generar una clase de comando Console (Symfony Console).\n\nRecomendado:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nNotas:\n  - El nombre del comando admite segmentos con dos puntos (ej. user:list). El nombre de clase será en camelCase (UserList).\n  - Por defecto se genera en app/command (mayúsculas según el directorio existente).\n  - Con -p/--plugin se genera en plugin/<plugin>/app/command.\n  - Con -P/--path se genera en el directorio relativo indicado (respecto a la raíz).\n  - Si el archivo existe, pregunta antes de sobrescribir; -f/--force sobrescribe directamente.",
            'pt_BR' => "Gerar uma classe de comando Console (Symfony Console).\n\nRecomendado:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nNotas:\n  - O nome do comando aceita segmentos com dois pontos (ex. user:list). O nome da classe será em camelCase (UserList).\n  - Por padrão gera em app/command (maiúsculas conforme o diretório existente).\n  - Com -p/--plugin gera em plugin/<plugin>/app/command.\n  - Com -P/--path gera no diretório relativo indicado (em relação à raiz do projeto).\n  - Se o arquivo existir, pergunta antes de sobrescrever; -f/--force sobrescreve diretamente.",
            'ru' => "Создать класс команды Console (Symfony Console).\n\nРекомендуется:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nПримечания:\n  - Имя команды может содержать двоеточие (напр. user:list), имя класса будет в camelCase (UserList).\n  - По умолчанию создаётся в app/command (регистр по существующей директории).\n  - С -p/--plugin создаётся в plugin/<plugin>/app/command.\n  - С -P/--path создаётся в указанной относительной директории (от корня проекта).\n  - Если файл существует, запрашивается подтверждение перезаписи; -f/--force перезаписывает сразу.",
            'vi' => "Tạo lớp lệnh Console (Symfony Console).\n\nKhuyến nghị:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nLưu ý:\n  - Tên lệnh hỗ trợ phân đoạn bằng dấu hai chấm (vd. user:list), tên lớp sẽ là camelCase (UserList).\n  - Mặc định tạo trong app/command (chữ hoa/thường theo thư mục hiện có).\n  - Với -p/--plugin tạo trong plugin/<plugin>/app/command.\n  - Với -P/--path tạo trong thư mục tương đối chỉ định (so với thư mục gốc dự án).\n  - Nếu tệp đã tồn tại sẽ hỏi trước khi ghi đè; -f/--force ghi đè trực tiếp.",
            'tr' => "Bir Console komut sınıfı oluştur (Symfony Console).\n\nÖnerilen:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nNotlar:\n  - Komut adı iki nokta ile ayrılmış parçalar destekler (örn. user:list), sınıf adı camelCase (UserList) olur.\n  - Varsayılan olarak app/command altında oluşturulur (büyük/küçük harf mevcut dizine göre).\n  - -p/--plugin ile plugin/<plugin>/app/command altında oluşturulur.\n  - -P/--path ile belirtilen göreli dizinde oluşturulur (proje köküne göre).\n  - Dosya varsa üzerine yazmadan önce sorar; -f/--force doğrudan üzerine yazar.",
            'id' => "Buat kelas perintah Console (Symfony Console).\n\nDirekomendasikan:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nCatatan:\n  - Nama perintah mendukung segmen dengan titik dua (mis. user:list), nama kelas akan camelCase (UserList).\n  - Secara default dibuat di app/command (huruf mengikuti direktori yang ada).\n  - Dengan -p/--plugin dibuat di plugin/<plugin>/app/command.\n  - Dengan -P/--path dibuat di direktori relatif yang ditentukan (terhadap akar proyek).\n  - Jika file sudah ada akan ditanya sebelum menimpa; -f/--force menimpa langsung.",
            'th' => "สร้างคลาสคำสั่ง Console (Symfony Console)\n\nแนะนำ:\n  php webman make:command user:list\n  php webman make:command user:list -p admin\n  php webman make:command user:list -P plugin/admin/app/command\n  php webman make:command user:list -f\n\nหมายเหตุ:\n  - ชื่อคำสั่งรองรับส่วนที่คั่นด้วยโคลอน (เช่น user:list) ชื่อคลาสจะเป็น camelCase (UserList)\n  - ค่าเริ่มต้นสร้างใต้ app/command (ตัวพิมพ์ตามไดเรกทอรีที่มีอยู่)\n  - ใช้ -p/--plugin สร้างใต้ plugin/<plugin>/app/command\n  - ใช้ -P/--path สร้างในไดเรกทอรีสัมพัทธ์ที่ระบุ (เทียบกับรากโปรเจกต์)\n  - ถ้ามีไฟล์อยู่แล้วจะถามก่อนเขียนทับ -f/--force เขียนทับโดยตรง",
        ];
        return Util::selectByLocale($helps);
    }

}
