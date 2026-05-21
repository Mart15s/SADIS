import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import Button from '../../components/ui/Button.jsx'

export default function NotFoundPage() {
  return (
    <div className="page-stack">
      <PageHeader
        title="Puslapis nerastas"
        description="Tokio puslapio nėra. Naudokite šoninę navigaciją ir grįžkite į sistemos modulius."
      />
      <div className="panel">
        <Link to="/">
          <Button>Grįžti į pradžią</Button>
        </Link>
      </div>
    </div>
  )
}
