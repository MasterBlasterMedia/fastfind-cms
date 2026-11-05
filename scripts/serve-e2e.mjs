import { spawn, spawnSync } from 'node:child_process';
import { generateKeyPairSync, randomBytes, randomUUID } from 'node:crypto';
import { closeSync, existsSync, mkdirSync, openSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const localPhp = path.resolve(root, '../.runtime/php/php.exe');
const php = process.env.PHP_BINARY || (existsSync(localPhp) ? localPhp : 'php');
const id = process.env.FASTFIND_E2E_RUN_ID || randomUUID();
if (!/^[a-f0-9-]{36}$/.test(id)) throw new Error('Invalid browser run identifier.');
const storage = path.join(root, 'storage/framework/testing', `run-${id}`);
// Laravel treats drive-letter cache paths as relative. Use portable project-relative cache paths.
const cache = `storage/framework/testing/run-${id}/framework/cache`;
mkdirSync(path.dirname(storage), { recursive: true });
mkdirSync(storage);
for (const directory of ['framework/testing', 'framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/public', 'app/private']) {
  mkdirSync(path.join(storage, directory), { recursive: true });
}
const logPath = path.join(storage, 'logs/php-server.log');
const log = openSync(logPath, 'a');
const signingPair = generateKeyPairSync('ed25519');
const publicKey = signingPair.publicKey.export({ format: 'der', type: 'spki' }).subarray(-32);
const privateSeed = signingPair.privateKey.export({ format: 'der', type: 'pkcs8' }).subarray(-32);
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_URL: 'http://127.0.0.1:8123', DB_CONNECTION: 'sqlite', DB_URL: '', DB_DATABASE: path.join(storage, `framework/testing/e2e-${id}.sqlite`),
  LARAVEL_STORAGE_PATH: storage, FILESYSTEM_DISK: 'local',
  APP_CONFIG_CACHE: `${cache}/config.php`,
  APP_SERVICES_CACHE: `${cache}/services.php`,
  APP_PACKAGES_CACHE: `${cache}/packages.php`,
  APP_ROUTES_CACHE: `${cache}/routes.php`,
  APP_EVENTS_CACHE: `${cache}/events.php`,
  VIEW_COMPILED_PATH: path.join(storage, 'framework/views'),
  SESSION_DRIVER: 'file', SESSION_COOKIE: `fastfind_e2e_${id}`, SESSION_SECURE_COOKIE: 'false',
  SESSION_DOMAIN: '', SESSION_PATH: '/', LOG_CHANNEL: 'single', LOG_STACK: 'single',
  CACHE_STORE: 'file', CACHE_PREFIX: `e2e_${id}`, QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log', MAIL_LOG_CHANNEL: 'single',
  FASTFIND_BACKUPS_ENABLED: 'false', FASTFIND_BACKUP_SCHEDULED: 'false',
  FASTFIND_MARKETPLACE_URL: '', FASTFIND_MARKETPLACE_SITE_URL: '', FASTFIND_MARKETPLACE_HOSTS: '',
  FASTFIND_PLUGIN_PUBLISHER_KEY: publicKey.toString('base64'),
  FASTFIND_E2E_SIGNING_KEY: Buffer.concat([privateSeed, publicKey]).toString('base64') };
delete env.PHP_CLI_SERVER_WORKERS;
console.info(`Disposable browser storage: ${storage}\nPHP setup and request log: ${logPath}`);
const setup = spawnSync(php, ['scripts/setup-e2e.php'], { cwd: root, env, stdio: ['ignore', log, log], windowsHide: true });
if (setup.error || setup.status !== 0) {
  closeSync(log);
  console.error(`Browser fixture setup failed. See ${logPath}`);
  if (setup.error) console.error(setup.error.message);
  process.exit(setup.status || 1);
}
delete env.FASTFIND_E2E_SIGNING_KEY;
// Direct PHP server: no orphaned artisan child process, no production database access.
// Exercise configurations without $_ENV so the router must bind the isolated storage explicitly.
const server = spawn(php, ['-d', 'variables_order=GPCS', '-S', '127.0.0.1:8123', path.join(root, 'scripts/e2e-router.php')],
  { cwd: path.join(root, 'public'), env, stdio: ['ignore', log, log], windowsHide: true });
closeSync(log);
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { server.kill(); process.exit(0); });
server.on('error', error => { console.error(`${error.message}; see ${logPath}`); process.exit(1); });
server.on('exit', code => process.exit(code || 0));
