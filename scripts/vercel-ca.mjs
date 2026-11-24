import { writeFileSync } from 'node:fs';
import { rootCertificates } from 'node:tls';

writeFileSync('bootstrap/vercel-ca.pem', rootCertificates.join('\n') + '\n');
