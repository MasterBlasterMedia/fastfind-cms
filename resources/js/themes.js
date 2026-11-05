const color = (value, fallback) => /^#[a-f\d]{6}$/i.test(value || '') ? value : fallback;

export function themeStyle(settings = {}) {
  const accent = color(settings.accent, '#3159d9');
  const channels = [1, 3, 5].map(start => parseInt(accent.slice(start, start + 2), 16) / 255)
    .map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
  const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
  return {
    '--theme-accent': accent,
    '--theme-accent-text': luminance > 0.179 ? '#000000' : '#ffffff',
    '--theme-background': color(settings.background, '#fbfaf7'),
    '--theme-surface': color(settings.surface, '#ffffff'),
    '--theme-text': color(settings.text, '#172431'),
    '--theme-muted': color(settings.muted, '#52665c'),
    '--theme-font': settings.font === 'serif' ? 'Georgia, serif' : 'Inter, ui-sans-serif, system-ui, sans-serif',
    '--theme-width': settings.width === 'normal' ? '1000px' : '1280px',
    '--theme-radius': ({ square: '0px', soft: '12px', rounded: '24px' })[settings.radius] || '12px',
    '--theme-spacing': ({ compact: '0.8', normal: '1', relaxed: '1.25' })[settings.spacing] || '1',
  };
}
