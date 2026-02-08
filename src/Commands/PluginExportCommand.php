<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webman\Console\Util;
use Webman\Console\Commands\Concerns\PluginCommandHelpers;

#[AsCommand('plugin:export', 'Plugin export')]
class PluginExportCommand extends Command
{
    use PluginCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        // Do NOT use "-n": Symfony Console already reserves "-n" for "--no-interaction".
        $this->addArgument('name', InputArgument::OPTIONAL, 'Plugin name, for example foo/my-admin');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Plugin name, for example foo/my-admin');
        $this->addOption('source', 's', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Directories to export');
        $this->setHelp($this->buildHelpText());
        $this->addUsage('foo/my-admin --source app --source config');
        $this->addUsage('--name foo/my-admin --source app --source config');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameArg = $this->normalizePluginName($input->getArgument('name'));
        $nameOpt = $this->normalizePluginName($input->getOption('name'));
        if ($nameArg && $nameOpt && $nameArg !== $nameOpt) {
            $output->writeln($this->pluginMsg('name_conflict', ['{arg}' => $nameArg, '{opt}' => $nameOpt]));
            return Command::FAILURE;
        }
        $nameRaw = $nameOpt ?: $nameArg;
        if (!$nameRaw) {
            $output->writeln($this->pluginMsg('missing_name'));
            return Command::INVALID;
        }
        if (!$this->isValidComposerPackageName($nameRaw)) {
            $output->writeln($this->pluginMsg('bad_name', ['{name}' => (string)$nameRaw]));
            return Command::INVALID;
        }

        $output->writeln($this->pluginMsg('export_title', ['{name}' => $nameRaw]));

        $namespace = Util::nameToNamespace($nameRaw);

        $pathRelations = $input->getOption('source');
        $pathRelations = is_array($pathRelations) ? $pathRelations : [];
        $pathRelations = array_values(array_filter(array_map('trim', $pathRelations), static fn($v) => $v !== ''));

        $pluginConfigDir = "config/plugin/{$nameRaw}";
        if (!in_array($pluginConfigDir, $pathRelations, true) && is_dir($pluginConfigDir)) {
            $pathRelations[] = $pluginConfigDir;
        }

        $originalDest = base_path() . "/vendor/{$nameRaw}";
        $dest = $originalDest . '/src';

        $this->writeInstallFile($namespace, $pathRelations, $dest);
        $output->writeln($this->pluginMsg('export_install_created', ['{path}' => $this->toRelativePath($dest . '/Install.php')]));

        foreach ($pathRelations as $source) {
            $source = $this->normalizeRelativePath((string)$source);
            if ($source === '' || (!is_dir($source) && !is_file($source))) {
                $output->writeln($this->pluginMsg('export_skip_missing', ['{path}' => $source]));
                continue;
            }
            $basePath = pathinfo("{$dest}/{$source}", PATHINFO_DIRNAME);
            if (!is_dir($basePath)) {
                mkdir($basePath, 0777, true);
            }
            $output->writeln($this->pluginMsg('export_copy', [
                '{src}' => $source,
                '{dest}' => $this->toRelativePath("{$dest}/{$source}"),
            ]));
            copy_dir($source, "{$dest}/{$source}");
        }

        $output->writeln($this->pluginMsg('export_saved', [
            '{name}' => $nameRaw,
            '{dest}' => $this->toRelativePath($originalDest),
        ]));
        return Command::SUCCESS;
    }

    /**
     * @param $namespace
     * @param $path_relations
     * @param $dest_dir
     * @return void
     */
    protected function writeInstallFile($namespace, $path_relations, $dest_dir)
    {
        if (!is_dir($dest_dir)) {
           mkdir($dest_dir, 0777, true);
        }
        $relations = [];
        foreach($path_relations as $relation) {
            $relations[$relation] = $relation;
        }
        $relations = var_export($relations, true);
        $install_php_content = <<<EOT
<?php
namespace $namespace;

class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * @var array
     */
    protected static \$pathRelation = $relations;

    /**
     * Install
     * @return void
     */
    public static function install()
    {
        static::installByRelation();
    }

    /**
     * Uninstall
     * @return void
     */
    public static function uninstall()
    {
        self::uninstallByRelation();
    }

    /**
     * installByRelation
     * @return void
     */
    public static function installByRelation()
    {
        foreach (static::\$pathRelation as \$source => \$dest) {
            if (\$pos = strrpos(\$dest, '/')) {
                \$parent_dir = base_path().'/'.substr(\$dest, 0, \$pos);
                if (!is_dir(\$parent_dir)) {
                    mkdir(\$parent_dir, 0777, true);
                }
            }
            //symlink(__DIR__ . "/\$source", base_path()."/\$dest");
            copy_dir(__DIR__ . "/\$source", base_path()."/\$dest");
            echo "Create \$dest\r\n";
        }
    }

    /**
     * uninstallByRelation
     * @return void
     */
    public static function uninstallByRelation()
    {
        foreach (static::\$pathRelation as \$source => \$dest) {
            \$path = base_path()."/\$dest";
            if (!is_dir(\$path) && !is_file(\$path)) {
                continue;
            }
            echo "Remove \$dest\r\n";
            if (is_file(\$path) || is_link(\$path)) {
                unlink(\$path);
                continue;
            }
            remove_dir(\$path);
        }
    }
    
}
EOT;
        file_put_contents("$dest_dir/Install.php", $install_php_content);
    }

    protected function buildHelpText(): string
    {
        $zh = <<<'EOF'
将指定目录打包导出到 vendor/<vendor>/<name>/src，并生成 Install.php（用于 plugin:install / plugin:uninstall）。

用法：
  php webman plugin:export foo/my-admin --source app --source config
  php webman plugin:export --name foo/my-admin --source app --source config

说明：
  - `--source/-s` 可重复多次指定要导出的目录/文件（相对项目根目录）。
  - 若存在 `config/plugin/<vendor>/<name>` 且未显式包含，会自动追加到导出列表。
EOF;
        $en = <<<'EOF'
Export directories into vendor/<vendor>/<name>/src and generate Install.php (for plugin:install / plugin:uninstall).

Usage:
  php webman plugin:export foo/my-admin --source app --source config
  php webman plugin:export --name foo/my-admin --source app --source config

Notes:
  - `--source/-s` can be provided multiple times (relative to project root).
  - If `config/plugin/<vendor>/<name>` exists and not provided, it will be appended automatically.
EOF;
        return Util::selectByLocale([
            'zh_CN' => $zh, 'zh_TW' => $zh, 'en' => $en,
            'ja' => "指定ディレクトリを vendor/<vendor>/<name>/src にエクスポートし Install.php を生成（plugin:install / plugin:uninstall 用）。\n\n用法：\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\n説明：\n  - `--source/-s` は複数指定可（プロジェクトルート相対）。\n  - `config/plugin/<vendor>/<name>` が存在し未指定の場合は自動で追加。",
            'ko' => "디렉터리를 vendor/<vendor>/<name>/src로 내보내고 Install.php 생성 (plugin:install / plugin:uninstall용).\n\n사용법:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\n참고:\n  - `--source/-s`는 여러 번 지정 가능(프로젝트 루트 기준).\n  - `config/plugin/<vendor>/<name>`이 있는데 지정하지 않으면 자동 추가.",
            'fr' => "Exporter des répertoires vers vendor/<vendor>/<name>/src et générer Install.php (pour plugin:install / plugin:uninstall).\n\nUsage :\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nNotes :\n  - `--source/-s` peut être fourni plusieurs fois (relatif à la racine).\n  - Si config/plugin/<vendor>/<name> existe et n'est pas fourni, il sera ajouté automatiquement.",
            'de' => "Verzeichnisse nach vendor/<vendor>/<name>/src exportieren und Install.php erzeugen (für plugin:install / plugin:uninstall).\n\nVerwendung:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nHinweise:\n  - `--source/-s` kann mehrfach angegeben werden (relativ zur Projektwurzel).\n  - Wenn config/plugin/<vendor>/<name> existiert und nicht angegeben, wird es automatisch ergänzt.",
            'es' => "Exportar directorios a vendor/<vendor>/<name>/src y generar Install.php (para plugin:install / plugin:uninstall).\n\nUso:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nNotas:\n  - `--source/-s` puede indicarse varias veces (respecto a la raíz).\n  - Si existe config/plugin/<vendor>/<name> y no se indica, se añade automáticamente.",
            'pt_BR' => "Exportar diretórios para vendor/<vendor>/<name>/src e gerar Install.php (para plugin:install / plugin:uninstall).\n\nUso:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nNotas:\n  - `--source/-s` pode ser fornecido várias vezes (em relação à raiz do projeto).\n  - Se config/plugin/<vendor>/<name> existir e não for fornecido, será anexado automaticamente.",
            'ru' => "Экспорт каталогов в vendor/<vendor>/<name>/src и создание Install.php (для plugin:install / plugin:uninstall).\n\nИспользование:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nПримечания:\n  - `--source/-s` можно указать несколько раз (относительно корня проекта).\n  - Если существует config/plugin/<vendor>/<name> и не указан, он будет добавлен автоматически.",
            'vi' => "Xuất thư mục vào vendor/<vendor>/<name>/src và tạo Install.php (cho plugin:install / plugin:uninstall).\n\nCách dùng:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nLưu ý:\n  - `--source/-s` có thể chỉ định nhiều lần (tương đối thư mục gốc).\n  - Nếu tồn tại config/plugin/<vendor>/<name> mà không chỉ định thì sẽ tự thêm vào.",
            'tr' => "Dizinleri vendor/<vendor>/<name>/src içine aktar ve Install.php oluştur (plugin:install / plugin:uninstall için).\n\nKullanım:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nNotlar:\n  - `--source/-s` birden fazla verilebilir (proje köküne göre).\n  - config/plugin/<vendor>/<name> varsa ve verilmediyse otomatik eklenir.",
            'id' => "Ekspor direktori ke vendor/<vendor>/<name>/src dan buat Install.php (untuk plugin:install / plugin:uninstall).\n\nPenggunaan:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nCatatan:\n  - `--source/-s` dapat diberikan beberapa kali (relatif ke akar proyek).\n  - Jika config/plugin/<vendor>/<name> ada dan tidak diberikan, akan ditambahkan otomatis.",
            'th' => "ส่งออกไดเรกทอรีไป vendor/<vendor>/<name>/src และสร้าง Install.php (สำหรับ plugin:install / plugin:uninstall)\n\nวิธีใช้:\n  php webman plugin:export foo/my-admin --source app --source config\n  php webman plugin:export --name foo/my-admin --source app --source config\n\nหมายเหตุ:\n  - `--source/-s` ระบุได้หลายครั้ง (เทียบกับรากโปรเจกต์)\n  - ถ้ามี config/plugin/<vendor>/<name> และไม่ได้ระบุ จะเพิ่มให้อัตโนมัติ",
        ]);
    }

}
