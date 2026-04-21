import { Navigate } from 'react-router-dom'

export default function CatalogPlantsPage() {
  return <Navigate replace to="/plants?view=catalog" />
}
