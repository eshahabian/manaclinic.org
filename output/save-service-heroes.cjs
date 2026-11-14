const fs = require('fs');
const path = require('path');
const sharp = require('sharp');
async function main() {
  const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, 'service-heroes-manifest.json'), 'utf8'));
  const roots = ['D:/Psychology/cpanel', 'D:/Psychology/manaclinic.org/cpanel'];
  const tiles = [];
  for (let i = 0; i < manifest.images.length; i++) {
    const item = manifest.images[i];
    const buffer = await sharp(item.file).resize(1600, 900, { fit: 'cover' }).webp({ quality: 86 }).toBuffer();
    for (const root of roots) {
      const image = path.join(root, `assets/img/services/${item.name}-hero-v3.webp`);
      fs.writeFileSync(image, buffer);
      const php = fs.readFileSync(path.join(root, `pages/service_${item.name}.php`), 'utf8');
      if (!php.includes(`${item.name}-hero-v3.webp`) || !php.includes('service-detail-hero-media')) throw Error('Missing hero reference: ' + item.name);
    }
    tiles.push({ input: await sharp(buffer).resize(640, 360).png().toBuffer(), left: i % 2 * 640, top: Math.floor(i / 2) * 360 });
    console.log(item.name, buffer.length);
  }
  await sharp({ create: { width: 1280, height: 1080, channels: 3, background: '#ffffff' } }).composite(tiles).jpeg({ quality: 90 }).toFile(path.join(__dirname, 'service-heroes-preview.jpg'));
  for (const root of roots) {
    if (fs.readFileSync(path.join(root, 'pages/services.php'), 'utf8').includes('services-image-note')) throw Error('Disclosure still present');
    if (!fs.readFileSync(path.join(root, 'assets/css/style.css'), 'utf8').includes('backdrop-filter: blur(16px)')) throw Error('Glass styling missing');
  }
  console.log('Verified all six hero images, references, removed note and glass styling in both checkouts.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
