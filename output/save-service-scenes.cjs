const fs = require('fs');
const path = require('path');
const sharp = require('sharp');
async function main() {
  const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, 'service-scenes-manifest.json'), 'utf8'));
  const roots = ['D:/Psychology/cpanel', 'D:/Psychology/manaclinic.org/cpanel'];
  const tiles = [];
  for (let i = 0; i < manifest.images.length; i++) {
    const item = manifest.images[i];
    const buffer = await sharp(item.file).resize(1200, 800, { fit: 'cover' }).webp({ quality: 86 }).toBuffer();
    for (const root of roots) fs.writeFileSync(path.join(root, 'assets/img/services', item.name + '-scene-v2.webp'), buffer);
    tiles.push({ input: await sharp(buffer).resize(480, 320).png().toBuffer(), left: (i % 3) * 480, top: Math.floor(i / 3) * 320 });
    console.log(item.name, buffer.length);
  }
  await sharp({ create: { width: 1440, height: 960, channels: 3, background: '#ffffff' } }).composite(tiles).jpeg({ quality: 90 }).toFile(path.join(__dirname, 'service-scenes-preview.jpg'));
  for (const root of roots) {
    const page = fs.readFileSync(path.join(root, 'pages/services.php'), 'utf8');
    const icons = [...page.matchAll(/'icon' => '([^']+)'/g)].map(m => m[1]);
    if (icons.length !== 9 || icons.some(icon => !fs.existsSync(path.join(root, 'assets/img/services', icon + '-scene-v2.webp')))) throw new Error('Missing card image');
    if (page.includes('service-card-background')) throw new Error('Old hidden image class');
    console.log(root, 'all 9 card images verified');
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
