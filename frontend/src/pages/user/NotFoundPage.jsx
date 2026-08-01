import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import Button from '../../components/ui/Button.jsx'

export default function NotFoundPage() {
  return (
    <div className="page-stack">
      <PageHeader
        title="Page not found"
        description="This page does not exist. Use the sidebar to return to an application section."
      />
      <div className="panel">
        <Link to="/">
          <Button>Return home</Button>
        </Link>
      </div>
    </div>
  )
}
