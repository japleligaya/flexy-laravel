import { cpSync, mkdirSync, rmSync } from 'node:fs';

const output = '.vercel-public';
rmSync(output, { recursive: true, force: true });
mkdirSync(output + '/build', { recursive: true });

for (const asset of ['favicon.ico', 'robots.txt', 'flexy', 'payroll-pro', 'build/assets']) {
    cpSync('public/' + asset, output + '/' + asset, {
        recursive: true,
        filter: (path) => !/[\\/](?:\.[^\\/]+|[^\\/]+\.(?:php\d*|phtml|phar))$/i.test(path),
    });
}
