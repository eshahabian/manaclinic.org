from PIL import Image
from pathlib import Path
source=Path('C:/Users/Emad/.codex/generated_images/01a15b62-d366-7090-ae62-56d7ed6fd098/exec-d0524da5-6ac8-4688-8267-6c5821a66222.png')
im=Image.open(source)
print('Image:',im.size, im.mode)
if im.mode=='RGBA': print('Alpha range:',im.getchannel('A').getextrema())
im.thumbnail((1200,800),Image.Resampling.LANCZOS)
im.save('cpanel/assets/img/therapist-guide-cartoon-v2.webp',quality=90,method=6)
print('WebP bytes:',Path('cpanel/assets/img/therapist-guide-cartoon-v2.webp').stat().st_size)
