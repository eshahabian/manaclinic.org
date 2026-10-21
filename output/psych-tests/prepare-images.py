from pathlib import Path
import json
from PIL import Image, ImageOps, ImageDraw
root=Path('D:/Psychology')
manifest=json.loads((root/'output/psych-tests/image-prompts.json').read_text('utf-8'))
dest=root/'cpanel/assets/img/tests';dest.mkdir(parents=True,exist_ok=True)
next_dest=root/'public/images/tests';next_dest.mkdir(parents=True,exist_ok=True)
sheet=Image.new('RGB',(1120,500),'#f5f0f8');draw=ImageDraw.Draw(sheet)
results=[]
for index,asset in enumerate(manifest['assets']):
    source=Image.open(asset['path']).convert('RGB')
    source.thumbnail((512,512),Image.Resampling.LANCZOS)
    output=dest/(asset['slug']+'.webp')
    assert not output.exists(), 'Do not overwrite an existing illustration'
    source.save(output,'WEBP',quality=86,method=6)
    (next_dest/output.name).write_bytes(output.read_bytes())
    preview=ImageOps.contain(source,(208,208))
    x=(index%5)*224+8;y=(index//5)*250+8
    sheet.paste(preview,(x,y));draw.text((x+8,y+215),asset['slug'],fill='#49335f')
    results.append({'slug':asset['slug'],'width':source.width,'height':source.height,'bytes':output.stat().st_size})
sheet.save(root/'output/psych-tests/image-contact-sheet.jpg',quality=90)
(root/'output/psych-tests/image-manifest.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
print(json.dumps(results))
