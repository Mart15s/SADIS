export default function BrandLogo({ className = '', alt = 'Yava' }) {
  return <img className={`brand-logo ${className}`.trim()} src="/brand/yava-logo.png" alt={alt} />
}
