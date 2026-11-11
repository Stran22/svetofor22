'use strict';
(() => {
  const form = document.getElementById('product-photo-form');
  if (!form) return;
  const input = form.querySelector('input[name="product_photo"]');
  const button = form.querySelector('button[type="submit"]');
  const note = document.getElementById('product-photo-status');
  const limit = 2 * 1024 * 1024;
  let working = false;
  const status = (message) => { if (note) note.textContent = message; };

  form.addEventListener('submit', async (event) => {
    if (working) { event.preventDefault(); return; }
    const file = input.files && input.files[0];
    if (!file) return;
    event.preventDefault();
    working = true;
    button.disabled = true;
    status('Подготовка фотографии…');
    let bitmap;
    let objectUrl;
    try {
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        throw new Error('Выберите JPEG, PNG или WebP.');
      }
      if (!window.File || !window.DataTransfer) {
        if (file.size > limit) throw new Error('Браузер не поддерживает сжатие. Выберите фото до 2 МБ.');
        form.submit();
        return;
      }
      if (typeof createImageBitmap === 'function') {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
      } else {
        objectUrl = URL.createObjectURL(file);
        bitmap = await new Promise((resolve, reject) => {
          const img = new Image();
          img.onload = () => resolve(img);
          img.onerror = () => reject(new Error('Не удалось прочитать фотографию.'));
          img.src = objectUrl;
        });
      }
      const width = bitmap.width || bitmap.naturalWidth;
      const height = bitmap.height || bitmap.naturalHeight;
      if (!width || !height || width > 6000 || height > 6000 || width * height > 20000000) {
        throw new Error('Слишком большое разрешение фотографии.');
      }
      const scale = Math.min(1, 500 / Math.max(width, height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(width * scale));
      canvas.height = Math.max(1, Math.round(height * scale));
      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('Сжатие фотографий недоступно.');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', 0.8));
      if (!blob || blob.type !== 'image/webp') {
        throw new Error('Браузер не поддерживает сохранение WebP.');
      }
      if (blob.size > limit) throw new Error('Фото после сжатия превышает 2 МБ.');
      const upload = new File([blob], 'product.webp', { type: 'image/webp' });
      const files = new DataTransfer();
      files.items.add(upload);
      input.files = files.files;
      status('Фото сжато до ' + Math.ceil(blob.size / 1024) + ' КБ. Загрузка…');
      form.submit();
    } catch (error) {
      status(error instanceof Error ? error.message : 'Не удалось подготовить фотографию.');
      working = false;
      button.disabled = false;
    } finally {
      if (bitmap && typeof bitmap.close === 'function') bitmap.close();
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    }
  });
})();
