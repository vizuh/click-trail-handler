const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..', '..');

function run(command, args, cwd, capture = false) {
  const result = spawnSync(command, args, { cwd, encoding: 'utf8' });

  if (result.error) {
    throw new Error(`Could not run required command "${command}": ${result.error.message}`);
  }

  if (!capture || result.status !== 0) {
    if (result.stdout) process.stdout.write(result.stdout);
    if (result.stderr) process.stderr.write(result.stderr);
  }

  if (result.status !== 0) {
    throw new Error(`${command} exited with code ${result.status}`);
  }

  return result.stdout;
}

function headerValue(source, name) {
  return source.match(new RegExp(`^\\s*\\*\\s*${name}:\\s*(.+?)\\s*$`, 'mi'))?.[1].trim();
}

function validateManifest(zipPath, slug) {
  const entries = new Set(
    run('zip', ['-sf', zipPath], root, true)
      .split(/\r?\n/)
      .map((line) => line.trim())
  );

  if (
    !entries.has(`${slug}/config/feature-registry.json`) ||
    entries.has(`${slug}/config/feature-test-matrix.json`)
  ) {
    throw new Error('Archive manifest validation failed');
  }
}

function makePosixZip() {
  const pluginMain = path.join(root, 'clicutcl.php');
  if (!fs.existsSync(pluginMain)) {
    throw new Error(`Could not find plugin main file at: ${pluginMain}`);
  }

  const source = fs.readFileSync(pluginMain, 'utf8');
  const slug = headerValue(source, 'Text Domain') || path.basename(root);
  const version = headerValue(source, 'Version') || 'dev';
  const outputDir = path.join(root, 'dist');
  const zipPath = path.join(outputDir, `${slug}-${version}.zip`);
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), `${slug}-build-`));
  const stageDir = path.join(tempRoot, slug);

  fs.mkdirSync(outputDir, { recursive: true });
  fs.mkdirSync(stageDir);
  fs.rmSync(zipPath, { force: true });

  try {
    run('rsync', ['-a', '--delete', '--exclude-from=.distignore', `${root}${path.sep}`, `${stageDir}${path.sep}`], root);
    run('zip', ['-qr', zipPath, slug], tempRoot);
    validateManifest(zipPath, slug);
  } catch (error) {
    fs.rmSync(zipPath, { force: true });
    throw error;
  } finally {
    fs.rmSync(tempRoot, { recursive: true, force: true });
  }

  console.log(`ZIP created: ${zipPath}`);
}

try {
  if (process.platform === 'win32') {
    run(
      'powershell.exe',
      ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(__dirname, 'make-zip.ps1')],
      root
    );
  } else {
    makePosixZip();
  }
} catch (error) {
  console.error(`Packaging failed: ${error.message}`);
  process.exitCode = 1;
}
