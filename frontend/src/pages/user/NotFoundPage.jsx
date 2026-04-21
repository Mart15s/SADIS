import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import Button from '../../components/ui/Button.jsx'

export default function NotFoundPage() {
  return (
    <div className="page-stack">
      <PageHeader
        title="Page not found"
        description="This route does not exist in the SPA. Use the sidebar to jump back into the supported modules."
      />
      <div className="panel">
        <Link to="/">
          <Button>Return to dashboard</Button>
        </Link>
      </div>
    </div>
  )
}
